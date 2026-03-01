<?php

declare(strict_types=1);

namespace Drupal\estimate\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access check for the "Convert to Work Order" operation.
 */
final class EstimateConvertToWorkOrderAccess {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
    );
  }

  public function access(EntityInterface $estimate, AccountInterface $account): AccessResult {
    if ($estimate->getEntityTypeId() !== 'estimate') {
      return AccessResult::forbidden();
    }

    // Basic permission gate (adjust as desired).
    if (!$account->hasPermission('update estimate entities')) {
      return AccessResult::forbidden();
    }

    $accepted_tid = (int) $this->configFactory->get('estimate.settings')->get('accepted_stage_tid');
    if ($accepted_tid <= 0) {
      return AccessResult::forbidden('Accepted stage is not configured.');
    }

    $stage_tid = (int) ($estimate->hasField('field_stage') && !$estimate->get('field_stage')->isEmpty()
      ? $estimate->get('field_stage')->target_id
      : 0);

    $is_current = (bool) ($estimate->hasField('field_is_current_revision') && !$estimate->get('field_is_current_revision')->isEmpty()
      ? $estimate->get('field_is_current_revision')->value
      : FALSE);

    $has_wo = (bool) ($estimate->hasField('field_work_order') && !$estimate->get('field_work_order')->isEmpty());

    if ($stage_tid !== $accepted_tid || !$is_current || $has_wo) {
      return AccessResult::forbidden();
    }

    if ($estimate->bundle() === 'estimate') {
      return AccessResult::forbidden('Legacy mapping is disabled.');
    }

    return AccessResult::allowed();
  }

}
