<?php

declare(strict_types=1);

namespace Drupal\bos_quickbooks\Soap;

use Drupal\bos_quickbooks\QbMonitor;
use Drupal\bos_quickbooks\QbSyncRunner;
use Drupal\bos_quickbooks\QbwcSession;
use Drupal\bos_quickbooks\QbXml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The eight operations the QuickBooks Web Connector calls, in its order:
 *
 *   serverVersion → clientVersion → authenticate →
 *   [ sendRequestXML → receiveResponseXML ]* → closeConnection
 *   (getLastError after an empty request or a negative percent;
 *    connectionError if QuickBooks itself could not be reached).
 *
 * PHP's SoapServer, in WSDL document/literal mode, hands each method ONE
 * object whose properties are the request element's children, and expects
 * an array keyed by the response element's child ("<op>Result"). Every
 * method is wrapped so the connector always gets a well-formed answer: a
 * thrown exception becomes an error the connector reports, never a 500 page.
 *
 * Return-value contract (as the widely used PHP implementations apply it —
 * Intuit's own pages are script-rendered and were not readable):
 *   authenticate      → [ticket, '' | company-file path | 'none' | 'nvu']
 *   sendRequestXML    → qbXML, or '' = nothing / error → connector asks getLastError
 *   receiveResponseXML→ 100 done · 0–99 more · negative = error → getLastError
 *   getLastError      → text; 'NoOp' = nothing wrong, pause and poll again
 *   closeConnection   → a closing message the connector logs
 *   connectionError   → 'done' = give up this run (or a company file to retry)
 */
final class QbwcSoapHandler {

  public const VERSION = '0.1.0';

  public function __construct(
    private readonly QbwcSession $session,
    private readonly QbSyncRunner $runner,
    private readonly QbXml $xml,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
  ) {}

  public function serverVersion($req): array {
    return ['serverVersionResult' => 'BOS QuickBooks Sync ' . self::VERSION];
  }

  public function clientVersion($req): array {
    $version = (string) ($req->strVersion ?? '');
    \Drupal::state()->set(QbMonitor::STATE_CONNECTOR_VERSION, $version);
    // '' = fine. 'W:…' would warn the user, 'E:…' refuse, 'O:x' = too old.
    return ['clientVersionResult' => ''];
  }

  public function authenticate($req): array {
    try {
      $ip = $this->ip();
      [$ticket, $status] = $this->session->authenticate($req->strUserName ?? '', $req->strPassword ?? '', $ip);
      if ($ticket !== '' && $status !== 'none') {
        $session = $this->session->load($ticket);
        if ($session) {
          $this->runner->start($session);
          $this->session->save($session);
        }
      }
      return ['authenticateResult' => [$ticket, $status]];
    }
    catch (\Throwable $e) {
      $this->logger->error('authenticate failed: @m', ['@m' => $e->getMessage()]);
      return ['authenticateResult' => ['', 'nvu']];
    }
  }

  public function sendRequestXML($req): array {
    try {
      $session = $this->session->load($req->ticket ?? '');
      if (!$session) {
        $this->logger->warning('sendRequestXML with an unknown ticket from @ip.', ['@ip' => $this->ip()]);
        return ['sendRequestXMLResult' => ''];
      }
      if ((int) ($session['requests'] ?? 0) === 0) {
        // First call of the session: the connector tells us which company
        // file it opened and what qbXML it speaks.
        $hcp = $this->xml->parseHcp((string) ($req->strHCPResponse ?? ''));
        $session['company_file'] = (string) ($req->strCompanyFileName ?? '');
        $session['company_name'] = $hcp['company_name'];
        $this->session->recordCompany($session['company_file'], $hcp['company_name'], $hcp['product']);
        $major = (int) ($req->qbXMLMajorVers ?? 0);
        $minor = (int) ($req->qbXMLMinorVers ?? 0);
        $ours = (string) ($this->configFactory->get('bos_quickbooks.settings')->get('qbxml_version') ?: '16.0');
        $theirs = "$major.$minor";
        $session['qbxml_version'] = ($major > 0 && version_compare($theirs, $ours, '<')) ? $theirs : $ours;
        $this->logger->info('Web Connector session @t: company "@c" (@f), QuickBooks @p, qbXML @v (connector offers @theirs).', [
          '@t' => substr((string) $session['ticket'], 0, 8),
          '@c' => $hcp['company_name'],
          '@f' => $session['company_file'],
          '@p' => $hcp['product'],
          '@v' => $session['qbxml_version'],
          '@theirs' => $theirs,
        ]);
      }
      $xml = $this->runner->nextRequest($session);
      $this->session->save($session);
      return ['sendRequestXMLResult' => $xml];
    }
    catch (\Throwable $e) {
      $this->logger->error('sendRequestXML failed: @m', ['@m' => $e->getMessage()]);
      $this->noteError($req->ticket ?? '', 'sendRequestXML: ' . $e->getMessage());
      return ['sendRequestXMLResult' => ''];
    }
  }

  public function receiveResponseXML($req): array {
    try {
      $session = $this->session->load($req->ticket ?? '');
      if (!$session) {
        $this->logger->warning('receiveResponseXML with an unknown ticket from @ip.', ['@ip' => $this->ip()]);
        return ['receiveResponseXMLResult' => -1];
      }
      $percent = $this->runner->handleResponse($session, (string) ($req->response ?? ''), (string) ($req->hresult ?? ''), (string) ($req->message ?? ''));
      $this->session->save($session);
      return ['receiveResponseXMLResult' => $percent];
    }
    catch (\Throwable $e) {
      $this->logger->error('receiveResponseXML failed: @m', ['@m' => $e->getMessage()]);
      $this->noteError($req->ticket ?? '', 'receiveResponseXML: ' . $e->getMessage());
      return ['receiveResponseXMLResult' => -1];
    }
  }

  public function getLastError($req): array {
    $session = $this->session->load($req->ticket ?? '');
    if (!$session) {
      return ['getLastErrorResult' => 'Bad ticket.'];
    }
    $error = (string) ($session['last_error'] ?? '');
    return ['getLastErrorResult' => $error !== '' ? $error : 'NoOp'];
  }

  public function closeConnection($req): array {
    try {
      $session = $this->session->load($req->ticket ?? '');
      if ($session) {
        $this->runner->finish($session, 'closeConnection');
        $this->session->close($session, 'closeConnection');
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('closeConnection failed: @m', ['@m' => $e->getMessage()]);
    }
    return ['closeConnectionResult' => 'OK'];
  }

  public function connectionError($req): array {
    try {
      $this->logger->error('Web Connector could not reach QuickBooks: @h @m', ['@h' => (string) ($req->hresult ?? ''), '@m' => (string) ($req->message ?? '')]);
      $session = $this->session->load($req->ticket ?? '');
      if ($session) {
        $session['last_error'] = 'connectionError ' . ($req->hresult ?? '') . ' ' . ($req->message ?? '');
        $this->runner->finish($session, 'connectionError');
        $this->session->close($session, 'connectionError');
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('connectionError handling failed: @m', ['@m' => $e->getMessage()]);
    }
    return ['connectionErrorResult' => 'done'];
  }

  private function noteError(string $ticket, string $error): void {
    $session = $this->session->load($ticket);
    if ($session) {
      $session['last_error'] = $error;
      $this->session->save($session);
    }
  }

  private function ip(): string {
    $request = $this->requestStack->getCurrentRequest();
    return $request ? (string) ($request->getClientIp() ?? '0.0.0.0') : '0.0.0.0';
  }

}
