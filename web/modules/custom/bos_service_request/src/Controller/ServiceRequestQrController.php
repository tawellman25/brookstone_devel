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
   * The campaign variants. `qr` = needs a printed QR on the page.
   */
  private const CAMPAIGNS = [
    'pc26a' => ['title' => 'Postcard A — "You\'re already on our list"', 'desc' => 'Reassurance mailing (current-year contract customers). Scans land on the Check-your-week page.', 'qr' => TRUE],
    'pc26b' => ['title' => 'Postcard B — "Time to schedule your winterization"', 'desc' => 'Conversion mailing (previously serviced, not on this year\'s list). Scans land on the signup form. Response on B justifies next year\'s spend — kept separate from A.', 'qr' => TRUE],
    'pc26' => ['title' => 'Legacy postcard (pc26)', 'desc' => 'Older single code — kept accepted for any test QR already printed; reported separately.', 'qr' => TRUE],
    'website' => ['title' => 'Website / direct link', 'desc' => 'People who type or click the address directly — no QR needed.', 'qr' => FALSE],
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
    // pc26a ("already on our list") lands on the Check-your-week page; every
    // other code lands on the signup form.
    $route = ($campaign === 'pc26a')
      ? 'bos_service_request.check_week'
      : 'bos_service_request.winterize';
    return Url::fromRoute($route, ['c' => $campaign])
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
    // Optional ?c=<code> renders just that variant's QR block (single download).
    $requested = (string) $request->query->get('c', '');
    $only = ($requested !== '' && isset(self::CAMPAIGNS[$requested])) ? $requested : NULL;

    $intro = '<p>The 2026 winterizing campaign variants and where each scan lands. '
      . 'Print each QR on its postcard variant <em>with the URL and phone beside it</em> — the QR is never the only path. '
      . 'Every scan is attributed to its code automatically, and the variants are reported separately.</p>';

    // Summary: every variant, its code, and its destination URL.
    $summary = '<table style="border-collapse:collapse;width:100%;max-width:860px;margin:0 0 2.5rem">'
      . '<thead><tr>'
      . '<th style="text-align:left;padding:6px 16px 6px 0;border-bottom:2px solid #e2d7c7">Variant</th>'
      . '<th style="text-align:left;padding:6px 16px;border-bottom:2px solid #e2d7c7">Code</th>'
      . '<th style="text-align:left;padding:6px 0;border-bottom:2px solid #e2d7c7">Where the scan lands</th>'
      . '</tr></thead><tbody>';
    foreach (self::CAMPAIGNS as $code => $meta) {
      $summary .= '<tr>'
        . '<td style="padding:10px 16px 10px 0;border-bottom:1px solid #eee;vertical-align:top"><strong>' . htmlspecialchars($meta['title']) . '</strong>'
        . '<br><span style="color:#6b5d4f;font-size:.9em">' . htmlspecialchars($meta['desc']) . '</span></td>'
        . '<td style="padding:10px 16px;border-bottom:1px solid #eee;font-family:monospace;vertical-align:top">' . htmlspecialchars($code) . '</td>'
        . '<td style="padding:10px 0;border-bottom:1px solid #eee;font-family:monospace;font-size:.82em;word-break:break-all;vertical-align:top">' . htmlspecialchars($this->winterizeUrl($code)) . '</td>'
        . '</tr>';
    }
    $summary .= '</tbody></table>';

    // Printable QR blocks (variants flagged qr => TRUE).
    $blocks = '<h2 style="margin:0 0 1rem">Printable QR codes</h2>';
    foreach (self::CAMPAIGNS as $code => $meta) {
      if (empty($meta['qr']) || ($only !== NULL && $code !== $only)) {
        continue;
      }
      $url = $this->winterizeUrl($code);
      $imgUrl = Url::fromRoute('bos_service_request.qr_png', [], ['query' => ['c' => $code]])->toString();
      $dlUrl = Url::fromRoute('bos_service_request.qr_png', [], ['query' => ['c' => $code, 'download' => 1]])->toString();
      $blocks .= '<div class="sr-qr-variant" style="margin-bottom:2rem">'
        . '<h3 style="margin-bottom:.25rem">' . htmlspecialchars($meta['title']) . ' <span style="font-family:monospace;font-weight:normal;color:#6b5d4f">(' . htmlspecialchars($code) . ')</span></h3>'
        . '<div style="text-align:center;padding:1rem;border:1px solid #ddd;border-radius:8px;background:#fff;max-width:640px">'
        . '<img src="' . htmlspecialchars($imgUrl) . '" alt="QR code for ' . htmlspecialchars($url) . '" style="width:360px;height:360px;max-width:100%" />'
        . '<p style="font-family:monospace;font-size:1.15rem;margin:.5rem 0 0;word-break:break-all">' . htmlspecialchars($url) . '</p>'
        . ($phone !== '' ? '<p style="font-size:1.1rem;margin:.25rem 0 0">Call the office: <strong>' . htmlspecialchars($phone) . '</strong></p>' : '')
        . '</div>'
        . '<p style="margin-top:.75rem"><a class="button button--primary" href="' . htmlspecialchars($dlUrl) . '">Download high-res PNG (' . htmlspecialchars($code) . ')</a></p>'
        . '</div>';
    }

    return [
      '#markup' => '<div class="sr-qr-asset">' . $intro . $summary . $blocks . '</div>',
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
