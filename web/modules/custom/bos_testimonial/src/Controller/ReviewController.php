<?php

declare(strict_types=1);

namespace Drupal\bos_testimonial\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * The public review landing page — the QR's destination.
 *
 * Offers BOTH paths to every visitor, in this order: the Google review button
 * first (it is what the QR invitation promises), then the direct testimonial form.
 * There is no "how did we do?" step branching between them — that is review
 * gating, which breaches Google's policy and gets reviews removed.
 */
class ReviewController extends ControllerBase {

  public function page(): array {
    $config = $this->config('bos_testimonial.settings');
    $url = trim((string) $config->get('google_review_url'));
    $invite = trim((string) $config->get('google_invite'));

    $build = [
      '#attached' => ['library' => ['bos_testimonial/review']],
      '#cache' => ['tags' => ['config:bos_testimonial.settings']],
    ];

    $build['wrap'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['review-page']],
    ];

    // Google first — and only when a URL is configured, so the page never shows a
    // dead CTA.
    if ($url !== '') {
      $build['wrap']['google'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['review-page__google']],
        'invite' => [
          '#markup' => '<p class="review-page__invite">' . ($invite !== '' ? htmlspecialchars($invite, ENT_QUOTES) : 'We would love your feedback.') . '</p>',
        ],
        'cta' => [
          '#type' => 'link',
          '#title' => $this->t('Post a review on Google'),
          '#url' => \Drupal\Core\Url::fromUri($url),
          '#attributes' => [
            'class' => ['button', 'button--primary', 'review-page__gbtn'],
            'rel' => 'noopener',
            'target' => '_blank',
          ],
        ],
        'note' => [
          '#markup' => '<p class="review-page__note">Opens Google. You will need to be signed in to a Google account — that is Google\'s requirement, not ours.</p>',
        ],
      ];
    }

    $build['wrap']['divider'] = [
      '#markup' => '<hr class="review-page__divider" />',
    ];

    $build['wrap']['form'] = $this->formBuilder()->getForm('Drupal\bos_testimonial\Form\ReviewForm');

    return $build;
  }

}
