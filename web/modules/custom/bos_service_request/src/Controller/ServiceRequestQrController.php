<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Print-ready postcard QR asset for the public /winterize form.
 *
 * The QR encodes the campaign URL (e.g. /winterize?c=pc26). The QR is never the
 * only path — the page also shows the URL text and office phone to print
 * alongside it (§7).
 */
final class ServiceRequestQrController extends ControllerBase {

  /**
   * Resolve the campaign code from ?c= against the allowlist (default pc26).
   */
  private function campaign(Request $request): string {
    $allow = $this->config('bos_service_request.settings')->get('campaigns') ?? [];
    $c = (string) $request->query->get('c', 'pc26');
    return in_array($c, $allow, TRUE) ? $c : 'pc26';
  }

  private function winterizeUrl(string $campaign): string {
    return Url::fromRoute('bos_service_request.winterize', ['c' => $campaign])
      ->setAbsolute()
      ->toString(TRUE)
      ->getGeneratedUrl();
  }

  private function qr(string $url, int $size): object {
    return (new Builder(
      writer: new PngWriter(),
      data: $url,
      encoding: new Encoding('UTF-8'),
      errorCorrectionLevel: ErrorCorrectionLevel::High,
      size: $size,
      margin: 16,
    ))->build();
  }

  /**
   * Print-ready page: QR + URL + phone + guidance + PNG download.
   */
  public function page(Request $request): array {
    $campaign = $this->campaign($request);
    $url = $this->winterizeUrl($campaign);
    $phone = (string) $this->config('bos_service_request.settings')->get('office_phone');
    // Point <img> at the PNG route (data: URIs are stripped by #markup XSS).
    $imgUrl = Url::fromRoute('bos_service_request.qr_png', [], ['query' => ['c' => $campaign]])->toString();
    $dlUrl = Url::fromRoute('bos_service_request.qr_png', [], ['query' => ['c' => $campaign, 'download' => 1]])->toString();

    $markup = '<div class="sr-qr-asset" style="max-width:640px">'
      . '<p>Campaign code: <strong>' . htmlspecialchars($campaign) . '</strong>. Print this QR on the postcard <em>with the URL and phone number beside it</em> — the QR is not the only path.</p>'
      . '<div style="text-align:center;padding:1rem;border:1px solid #ddd;border-radius:8px;background:#fff">'
      . '<img src="' . htmlspecialchars($imgUrl) . '" alt="QR code for ' . htmlspecialchars($url) . '" style="width:360px;height:360px;max-width:100%" />'
      . '<p style="font-family:monospace;font-size:1.15rem;margin:.5rem 0 0;word-break:break-all">' . htmlspecialchars($url) . '</p>'
      . ($phone !== '' ? '<p style="font-size:1.1rem;margin:.25rem 0 0">Call the office: <strong>' . htmlspecialchars($phone) . '</strong></p>' : '')
      . '</div>'
      . '<p style="margin-top:1rem"><a class="button button--primary" href="' . htmlspecialchars($dlUrl) . '">Download high-res PNG</a></p>'
      . '<p>The scan lands on the winterization signup and is attributed to this campaign automatically.</p>'
      . '</div>';

    return [
      '#markup' => $markup,
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Raw high-resolution PNG for the print shop.
   */
  public function png(Request $request): Response {
    $campaign = $this->campaign($request);
    $png = $this->qr($this->winterizeUrl($campaign), 1200)->getString();
    // Inline for the on-page <img>; attachment when ?download=1 (print shop).
    $disposition = $request->query->get('download')
      ? 'attachment; filename="winterize-qr-' . $campaign . '.png"'
      : 'inline; filename="winterize-qr-' . $campaign . '.png"';
    return new Response($png, 200, [
      'Content-Type' => 'image/png',
      'Content-Disposition' => $disposition,
      'Cache-Control' => 'private, max-age=3600',
    ]);
  }

}
