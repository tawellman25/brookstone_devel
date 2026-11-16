<?php

declare(strict_types=1);

namespace Drupal\bos_quickbooks;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Is the mirror current? — the fact every billing run will be gated on.
 *
 * A mirror is STALE when no complete sync (all three lists clean) has
 * finished within stale_hours. Cron emails the alert address when that
 * happens (once per alert_interval) and again when a clean run lands; the
 * Status report carries a row. Nothing is emailed before the first-ever
 * complete sync — until the connector is installed at the shop the row says
 * "never synced" and stays quiet.
 */
final class QbMonitor {

  public const STATE_LAST_ALERT = 'bos_quickbooks.last_stale_alert';
  public const STATE_ALERT_OPEN = 'bos_quickbooks.stale_alert_open';
  public const STATE_CONNECTOR_VERSION = 'bos_quickbooks.connector_version';

  public function __construct(
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly MailManagerInterface $mailManager,
    private readonly RequestStack $requestStack,
    private readonly QbListMirror $mirror,
    private readonly LoggerInterface $logger,
  ) {}

  public function lastFullSync(): ?array {
    $v = $this->state->get(QbSyncRunner::STATE_LAST_FULL_SYNC);
    return is_array($v) ? $v : NULL;
  }

  public function staleHours(): int {
    return (int) ($this->configFactory->get('bos_quickbooks.settings')->get('stale_hours') ?: 24);
  }

  /**
   * TRUE when the last complete sync is older than the window, or never.
   */
  public function isStale(): bool {
    $last = $this->lastFullSync();
    return !$last || ($this->time->getRequestTime() - (int) $last['at']) > $this->staleHours() * 3600;
  }

  /**
   * Where the alerts go: the setting, else the site mail.
   */
  public function recipient(): string {
    $to = (string) $this->configFactory->get('bos_quickbooks.settings')->get('alert_email');
    return $to !== '' ? $to : (string) $this->configFactory->get('system.site')->get('mail');
  }

  /**
   * Everything the status page and Status report row show.
   */
  public function inspect(): array {
    $last = $this->lastFullSync();
    $contact = $this->state->get(QbwcSession::STATE_LAST_CONTACT);
    $session = $this->state->get(QbwcSession::STATE_SESSION);
    $company = $this->state->get(QbwcSession::STATE_LAST_COMPANY);
    $run = $this->state->get(QbSyncRunner::STATE_LAST_RUN);
    $error = $this->state->get(QbSyncRunner::STATE_LAST_ERROR);
    return [
      'last_full_sync' => $last,
      'last_contact' => $contact === NULL ? NULL : (int) $contact,
      'session' => is_array($session) && !empty($session['ticket']) ? $session : NULL,
      'company' => is_array($company) ? $company : NULL,
      'last_run' => is_array($run) ? $run : NULL,
      'last_error' => is_array($error) ? $error : NULL,
      'connector_version' => (string) $this->state->get(self::STATE_CONNECTOR_VERSION),
      'stale' => $this->isStale(),
      'never' => $last === NULL,
      'stale_hours' => $this->staleHours(),
      'password_set' => (bool) $this->state->get(QbwcSession::STATE_PASSWORD),
      'alert_open' => (bool) $this->state->get(self::STATE_ALERT_OPEN),
      'recipient' => $this->recipient(),
      'counts' => $this->mirror->counts(),
      'match_counts' => $this->mirror->matchCounts(),
    ];
  }

  /**
   * Cron: email once per alert_interval while the mirror is stale.
   */
  public function cron(): void {
    $last = $this->lastFullSync();
    if (!$last || !$this->isStale()) {
      return;
    }
    $now = $this->time->getRequestTime();
    $interval = (int) ($this->configFactory->get('bos_quickbooks.settings')->get('alert_interval') ?: 86400);
    $last_alert = (int) $this->state->get(self::STATE_LAST_ALERT, 0);
    if ($now - $last_alert < $interval) {
      return;
    }
    $facts = $this->inspect();
    $this->logger->error('QuickBooks mirror is STALE: last complete sync @when, window @h h. Alert emailed to @to.', [
      '@when' => date('c', (int) $last['at']),
      '@h' => $this->staleHours(),
      '@to' => $facts['recipient'],
    ]);
    $this->send('stale', $facts);
    $this->state->set(self::STATE_LAST_ALERT, $now);
    $this->state->set(self::STATE_ALERT_OPEN, TRUE);
  }

  /**
   * A clean run landed: close an open alert with the all-clear.
   */
  public function recordFullSync(array $summary): void {
    if (!$this->state->get(self::STATE_ALERT_OPEN)) {
      return;
    }
    $facts = $this->inspect();
    $facts['summary'] = $summary;
    $this->send('recovered', $facts);
    $this->state->delete(self::STATE_ALERT_OPEN);
    $this->logger->info('QuickBooks mirror current again — all-clear emailed to @to.', ['@to' => $facts['recipient']]);
  }

  public function host(): string {
    $request = $this->requestStack->getCurrentRequest();
    return $request ? $request->getHost() : 'BOS';
  }

  private function send(string $key, array $facts): void {
    $to = $facts['recipient'] ?? $this->recipient();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
      $this->logger->error('QuickBooks alert NOT sent — no valid recipient (set alert_email or the site mail).');
      return;
    }
    $this->mailManager->mail('bos_quickbooks', $key, $to, 'en', ['facts' => $facts, 'host' => $this->host()]);
  }

}
