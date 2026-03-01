<?php

namespace Drupal\system_readiness\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Minimal controller placeholder for a future settings page.
 */
final class SystemReadinessSettingsController extends ControllerBase {

  public static function create(ContainerInterface $container): static {
    return new static();
  }

  public function page(): array {
    return [
      '#type' => 'item',
      '#markup' => $this->t('No settings yet. Manage items at Content → System Readiness.'),
    ];
  }

}
