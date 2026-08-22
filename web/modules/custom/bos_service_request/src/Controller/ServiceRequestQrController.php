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
   * The 2026 postcard variants (§P0.1). Two codes → two QR PNGs for the printer.
   */
  private const POSTCARD_VARIANTS = [
    'pc26a' => ['title' => 'Variant A — "You\'re already on our list"', 'desc' => 'Reassurance mailing (current-year contract customers).'],
    'pc26b' => ['title' => 'Variant B — "Time to schedule your Sprinkler Winterization"', 'desc' => 'Conversion mailing (previously serviced, not on this year\'s list). Response rate on B justifies next year\'s spend — keep it separate from A.'],
  ];

  /**
   * Resolve the campaign code from ?c= against the allowlist (default pc26a).
   */
  private function campaign(Request $request): string {
    $allow = $this->config('bos_service_request.settings')->get('campaigns') ?? [];
    $c = (string) $request->query->get('c', 'pc26a');
    return in_array($c, $allow, TRUE) ? $c : 'pc26a';
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
   * Print-ready page. By default renders BOTH postcard variants (A + B), each
   * with its own QR + download — the print shop needs two PNGs. `?c=<code>`
   * renders just that one (any allowlisted code, incl. legacy pc26 / website).
   */
  public function page(Request $request): array {
    $phone = (string) $this->config('bos_service_request.settings')->get('office_phone');
    $requested = (string) $request->query->get('c', '');

    if ($requested !== '') {
      $codes = [$this->campaign($request) => ['title' => 'Campaign: ' . $this->campaign($request), 'desc' => '']];
    }
    else {
      $codes = self::POSTCARD_VARIANTS;
    }

    $blocks = '';
    foreach ($codes as $code => $meta) {
      $url = $this->winterizeUrl($code);
      $imgUrl = Url::fromRoute('bos_service_request.qr_png', [], ['query' => ['c' => $code]])->toString();
      $dlUrl = Url::fromRoute('bos_service_request.qr_png', [], ['query' => ['c' => $code, 'download' => 1]])->toString();
      $blocks .= '<div class="sr-qr-variant" style="margin-bottom:2rem">'
        . '<h2 style="margin-bottom:.25rem">' . htmlspecialchars($meta['title']) . '</h2>'
        . ($meta['desc'] !== '' ? '<p style="margin-top:0;color:#555">' . htmlspecialchars($meta['desc']) . '</p>' : '')
        . '<p>Campaign code: <strong>' . htmlspecialchars($code) . '</strong></p>'
        . '<div style="text-align:center;padding:1rem;border:1px solid #ddd;border-radius:8px;background:#fff;max-width:640px">'
        . '<img src="' . htmlspecialchars($imgUrl) . '" alt="QR code for ' . htmlspecialchars($url) . '" style="width:360px;height:360px;max-width:100%" />'
        . '<p style="font-family:monospace;font-size:1.15rem;margin:.5rem 0 0;word-break:break-all">' . htmlspecialchars($url) . '</p>'
        . ($phone !== '' ? '<p style="font-size:1.1rem;margin:.25rem 0 0">Call the office: <strong>' . htmlspecialchars($phone) . '</strong></p>' : '')
        . '</div>'
        . '<p style="margin-top:.75rem"><a class="button button--primary" href="' . htmlspecialchars($dlUrl) . '">Download high-res PNG (' . htmlspecialchars($code) . ')</a></p>'
        . '</div>';
    }

    $intro = '<p>Print each QR on its postcard variant <em>with the URL and phone number beside it</em> — the QR is not the only path. Each scan is attributed to its campaign automatically; A and B are reported separately.</p>';

    return [
      '#markup' => '<div class="sr-qr-asset">' . $intro . $blocks . '</div>',
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
