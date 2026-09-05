<?php

declare(strict_types=1);

namespace Drupal\bos_homepage\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Render\Markup;

/**
 * @Block(
 *   id = "bos_homepage_phone",
 *   admin_label = @Translation("BOS phone (header)"),
 *   category = @Translation("BOS")
 * )
 *
 * Sitewide tap-to-call phone for the header region. Number comes from
 * bos_service_request.settings:office_phone so it stays in one place.
 */
class PhoneBlock extends BlockBase {

  public function build(): array {
    $phone = (string) \Drupal::config('bos_service_request.settings')->get('office_phone');
    if ($phone === '') {
      return [];
    }
    $tel = preg_replace('/[^0-9+]/', '', $phone);
    // Markup::create so the inline SVG survives (a plain '#markup' string runs
    // Xss::filterAdmin, which strips <svg>/<path>). Trusted, self-authored markup.
    return [
      '#markup' => Markup::create('<a class="bo-header-phone" href="tel:' . $tel . '">'
        . '<svg class="bo-header-phone__icon" width="17" height="17" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.28z"/></svg>'
        . htmlspecialchars($phone) . '</a>'),
      '#attached' => ['library' => ['bos_homepage/phone']],
      '#cache' => ['tags' => ['config:bos_service_request.settings']],
    ];
  }

}
