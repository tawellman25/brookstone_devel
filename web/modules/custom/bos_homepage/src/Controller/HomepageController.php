<?php

declare(strict_types=1);

namespace Drupal\bos_homepage\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Front-page controller. The actual bands render array is built by
 * _bos_homepage_render_array() (module), which is also used by
 * hook_preprocess_page() so the page--front template can render the bands
 * DIRECTLY — bypassing Olivero's content-region grid wrapper (which boxed them).
 */
class HomepageController extends ControllerBase {

  public function page(): array {
    return _bos_homepage_render_array();
  }

}
