<?php

declare(strict_types=1);

namespace Drupal\bos_testimonial\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Printable QR for /review.
 *
 * Two modes, both from one page:
 *  - generic — one code for a truck magnet, a door hanger, an invoice footer;
 *  - per-job — ?wo=NNN, so a review that arrives is attached to the work order it
 *    is about. That attribution is the thing a generic review card cannot do.
 *
 * Mirrors ServiceRequestQrController (same endroid Builder call and the same
 * inline-vs-download disposition, so the print shop gets a real file).
 */
class TestimonialQrController extends ControllerBase {

  private function reviewUrl(int $wo = 0): string {
    $options = ['absolute' => TRUE];
    if ($wo > 0) {
      $options['query']['wo'] = $wo;
    }
    $options['query']['c'] = 'qr';
    return Url::fromRoute('bos_testimonial.review', [], $options)->toString();
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

  public function image(Request $request): Response {
    $wo = (int) $request->query->get('wo', 0);
    $png = $this->qr($this->reviewUrl($wo), 1200)->getString();
    $name = $wo > 0 ? 'review-qr-wo-' . $wo . '.png' : 'review-qr.png';
    $disposition = $request->query->get('download')
      ? 'attachment; filename="' . $name . '"'
      : 'inline; filename="' . $name . '"';
    return new Response($png, 200, [
      'Content-Type' => 'image/png',
      'Content-Disposition' => $disposition,
      'Cache-Control' => 'private, max-age=3600',
    ]);
  }

  public function page(Request $request): array {
    $wo = (int) $request->query->get('wo', 0);
    $target = $this->reviewUrl($wo);
    $img = Url::fromRoute('bos_testimonial.qr_image', [], ['query' => array_filter(['wo' => $wo ?: NULL])])->toString();
    $dl = Url::fromRoute('bos_testimonial.qr_image', [], ['query' => array_filter(['wo' => $wo ?: NULL, 'download' => 1])])->toString();
    $invite = trim((string) $this->config('bos_testimonial.settings')->get('google_invite'));

    return [
      '#cache' => ['max-age' => 0],
      'intro' => [
        '#markup' => '<p>' . $this->t('Point a phone at this code and it opens the review page: the Google review button and a direct testimonial form, both offered to every customer.') . '</p>'
          . '<p><strong>' . $this->t('Destination:') . '</strong> <code>' . htmlspecialchars($target, ENT_QUOTES) . '</code></p>',
      ],
      'perjob' => [
        '#markup' => '<p>' . ($wo > 0
          ? $this->t('This code is tagged to work order @wo — a review arriving through it is attached to that job.', ['@wo' => $wo])
          : $this->t('Generic code. Add <code>?wo=NNN</code> to this page to print one tagged to a specific work order, so the review is attached to that job.')) . '</p>',
      ],
      'qr' => [
        '#markup' => '<p><img src="' . $img . '" alt="" width="320" height="320" /></p>'
          . '<p><a class="button" href="' . $dl . '">' . $this->t('Download the PNG (1200px)') . '</a></p>',
      ],
      'copy' => [
        '#markup' => '<h2>' . $this->t('Suggested wording to print beside it') . '</h2><blockquote><p>'
          . htmlspecialchars($invite !== '' ? $invite : 'We would love your feedback.', ENT_QUOTES)
          . '</p></blockquote>',
      ],
    ];
  }

}
