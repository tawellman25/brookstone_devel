<?php

declare(strict_types=1);

namespace Drupal\bos_quickbooks\Controller;

use Drupal\bos_quickbooks\Soap\QbwcSoapHandler;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /qbwc/soap — the Web Connector endpoint; /qbwc/support — its support page.
 */
final class QbwcController extends ControllerBase {

  public function __construct(
    private readonly QbwcSoapHandler $handler,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bos_quickbooks.soap_handler'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * GET ?wsdl → the contract; GET → one line; POST → SOAP.
   */
  public function endpoint(Request $request): Response {
    $wsdl = $this->moduleList->getPath('bos_quickbooks') . '/wsdl/QBWebConnectorSvc.wsdl';
    if ($request->isMethod('GET')) {
      if ($request->query->has('wsdl')) {
        $location = Url::fromRoute('bos_quickbooks.soap', [], ['absolute' => TRUE])->toString();
        $xml = preg_replace('/<soap:address location="[^"]*"/', '<soap:address location="' . htmlspecialchars($location, ENT_XML1) . '"', (string) file_get_contents($wsdl));
        return $this->noStore(new Response($xml, 200, ['Content-Type' => 'text/xml; charset=utf-8']));
      }
      return $this->noStore(new Response("BOS QuickBooks Web Connector endpoint. SOAP POST only; add ?wsdl for the contract.\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']));
    }
    $server = new \SoapServer($wsdl, [
      'uri' => 'http://developer.intuit.com/',
      'soap_version' => SOAP_1_1,
      'encoding' => 'UTF-8',
      'cache_wsdl' => WSDL_CACHE_NONE,
    ]);
    $server->setObject($this->handler);
    ob_start();
    try {
      $server->handle((string) $request->getContent());
    }
    finally {
      $out = (string) ob_get_clean();
    }
    // SoapServer::handle() queues its own Content-Type and Content-Length with
    // header(); the Response below sends them too, and nginx refuses a reply
    // with two Content-Length lines (502). Drop PHP's copies — the buffer above
    // means nothing has been flushed yet.
    if (!headers_sent()) {
      header_remove('Content-Length');
      header_remove('Content-Type');
    }
    return $this->noStore(new Response($out, 200, ['Content-Type' => 'text/xml; charset=utf-8']));
  }

  /**
   * The AppSupport page the connector links to.
   */
  public function support(): array {
    return [
      '#markup' => '<p>' . $this->t('This is the QuickBooks Web Connector application for BOS, the Brookstone Operating System. It mirrors QuickBooks customers, items and classes into BOS so invoices can be built from completed work orders.') . '</p>'
        . '<p>' . $this->t('If the connector reports an error, contact the Brookstone office administrator; the sync status is on the QuickBooks page inside BOS.') . '</p>',
      '#cache' => ['max-age' => 0],
    ];
  }

  private function noStore(Response $response): Response {
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    $response->headers->addCacheControlDirective('no-cache');
    return $response;
  }

}
