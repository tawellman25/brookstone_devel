<?php

declare(strict_types=1);

namespace Drupal\bos_testimonial\Service;

use Psr\Log\LoggerInterface;

/**
 * Blurs regions of a photographed note beyond recovery.
 *
 * Deliberately destructive. A soft gaussian blur can sometimes be partially
 * undone, and on a signature "partially" is the whole game — so each region is
 * pixelated at a large block size FIRST (which genuinely discards the detail)
 * and only then smoothed, so it reads as a blur rather than a mosaic.
 *
 * Regions come from a HUMAN, not from the model. Tested against a real note on
 * 2026-09-27, Claude read the text perfectly but placed its bounding boxes
 * ~190px high on a 700px image — the blur covered a harmless sentence and left
 * the signature, address and phone fully readable, while the result still
 * looked redacted. Padding cannot absorb an error that size, and a blur that
 * lands in the wrong place is worse than none because it invites trust.
 *
 * So redactBand() is the path the form uses: the office sets one horizontal
 * line and everything below it goes. On a thank-you note the signature block is
 * at the bottom, so one number covers it, and they set that number while
 * looking at the result. redact() with explicit boxes stays for callers that
 * have trustworthy coordinates.
 *
 * GD only — it is the configured toolkit on both environments, and imagick is
 * not installed on live.
 */
final class NoteRedactor {

  /**
   * Fraction of the image's short side added to every side of every box.
   */
  private const PAD_RATIO = 0.05;

  /**
   * Never pad less than this, for a box on a small image.
   */
  private const PAD_MIN_PX = 16;

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Can GD decode this? PDFs and HEICs cannot be redacted here.
   */
  public function supports(string $mimeType): bool {
    return in_array(strtolower($mimeType), [
      'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
    ], TRUE);
  }

  /**
   * Apply blur to normalised regions.
   *
   * @param string $binary
   *   The source image.
   * @param array $regions
   *   Each with a 'box' => [x, y, w, h] as fractions of the image, 0..1.
   * @param bool $outline
   *   Draw a marker around each redacted area. For the confirmation preview
   *   only — it makes visible what the model thought was personal, so the
   *   office can spot what it MISSED. Never on the saved image.
   *
   * @return string
   *   PNG binary. PNG because re-encoding a JPEG would soften the untouched
   *   parts of the note for no reason.
   */
  public function redact(string $binary, array $regions, bool $outline = FALSE, bool $pad = TRUE): string {
    $image = @imagecreatefromstring($binary);
    if ($image === FALSE) {
      throw new \RuntimeException('That image could not be opened for redaction.');
    }

    $imgW = imagesx($image);
    $imgH = imagesy($image);
    $padPx = $pad ? (int) max(self::PAD_MIN_PX, min($imgW, $imgH) * self::PAD_RATIO) : 0;

    $applied = 0;
    foreach ($regions as $region) {
      $box = $region['box'] ?? NULL;
      if (!is_array($box) || count($box) !== 4) {
        continue;
      }

      // Normalised -> pixels, padded, then clamped to the canvas.
      $x = (int) round($box[0] * $imgW) - $padPx;
      $y = (int) round($box[1] * $imgH) - $padPx;
      $w = (int) round($box[2] * $imgW) + $padPx * 2;
      $h = (int) round($box[3] * $imgH) + $padPx * 2;

      $x = max(0, $x);
      $y = max(0, $y);
      $w = min($w, $imgW - $x);
      $h = min($h, $imgH - $y);
      if ($w < 4 || $h < 4) {
        continue;
      }

      $patch = imagecreatetruecolor($w, $h);
      imagecopy($patch, $image, 0, 0, $x, $y, $w, $h);

      // Big blocks: this is the step that actually destroys the detail.
      $block = (int) max(12, min($w, $h) / 5);
      imagefilter($patch, IMG_FILTER_PIXELATE, $block, TRUE);
      // Then soften, so it reads as a blur rather than a mosaic.
      for ($i = 0; $i < 6; $i++) {
        imagefilter($patch, IMG_FILTER_GAUSSIAN_BLUR);
      }

      imagecopy($image, $patch, $x, $y, 0, 0, $w, $h);
      imagedestroy($patch);

      if ($outline) {
        $marker = imagecolorallocate($image, 203, 96, 21);
        $thickness = (int) max(2, min($imgW, $imgH) / 300);
        for ($t = 0; $t < $thickness; $t++) {
          imagerectangle($image, $x + $t, $y + $t, $x + $w - 1 - $t, $y + $h - 1 - $t, $marker);
        }
      }

      $applied++;
    }

    if ($applied === 0) {
      imagedestroy($image);
      throw new \RuntimeException('No usable regions to blur — nothing was changed.');
    }

    ob_start();
    imagepng($image, NULL, 6);
    $out = (string) ob_get_clean();
    imagedestroy($image);

    $this->logger->info('Redacted @n region(s) on a testimonial scan.', ['@n' => $applied]);
    return $out;
  }

  /**
   * Blur everything below a horizontal line — the whole width, to the bottom.
   *
   * No padding: a person set this line while looking at the image, so moving it
   * for them would only be surprising.
   *
   * @param float $from
   *   Where the blur starts, as a fraction of the image height (0..1).
   */
  public function redactBand(string $binary, float $from, bool $outline = FALSE): string {
    $from = max(0.0, min(0.98, $from));
    return $this->redact($binary, [[
      'type' => 'band',
      'where' => 'below the line',
      'box' => [0.0, $from, 1.0, 1.0 - $from],
    ]], $outline, FALSE);
  }

}
