<?php

declare(strict_types=1);

namespace Drupal\estimate\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Enforces Estimate revision chain rules deterministically.
 *
 * Revision chain scope:
 *   estimate_request_id + field_estimate_type target_id
 *
 * Rules:
 * - New estimate without field_revision_of -> revision_number = 1, is_current = TRUE
 * - Revision estimate -> revision_number = prior + 1, is_current = TRUE
 * - Only one estimate per chain may have field_is_current_revision = TRUE
 *
 * Notes:
 * - No hardcoded term IDs.
 * - This class does not create work orders.
 */
final class EstimateRevisionManager {

  private EntityStorageInterface $estimateStorage;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly \Psr\Log\LoggerInterface $logger,
  ) {
    $this->estimateStorage = $this->entityTypeManager->getStorage('estimate');
  }

  /**
   * Enforce revision invariants on an estimate entity prior to save.
   *
   * Call this from hook_entity_presave() when entity type == estimate.
   */
  public function enforce(EntityInterface $estimate): void {
    if ($estimate->getEntityTypeId() !== 'estimate') {
      return;
    }

    // Prevent re-entry when unsetOtherCurrents() triggers presave on related estimates.
    static $processing = [];
    $key = $estimate->isNew() ? 'new_' . spl_object_id($estimate) : (string) $estimate->id();
    if (isset($processing[$key])) {
      return;
    }
    $processing[$key] = TRUE;

    // Required fields for revision governance.
    foreach (['field_estimate_request', 'field_revision_number', 'field_is_current_revision'] as $required) {
      if (!$estimate->hasField($required)) {
        $this->logger->warning('EstimateRevisionManager skipped: missing field @field on estimate bundle @bundle.', [
          '@field' => $required,
          '@bundle' => $estimate->bundle(),
        ]);
        unset($processing[$key]);
        return;
      }
    }

    $estimate_request_id = $this->getTargetId($estimate, 'field_estimate_request');
    if ($estimate_request_id <= 0) {
      // EstimateRequest is required by your model; if missing we can't scope chain.
      unset($processing[$key]);
      return;
    }

    $estimate_type_id = $this->getTargetId($estimate, 'field_estimate_type'); // may be 0 if not set.

    // Determine chain key.
    $chain = [
      'estimate_request_id' => $estimate_request_id,
      'estimate_type_id' => $estimate_type_id,
    ];

    // Determine whether this is a revision (field_revision_of set).
    $revision_of_id = $this->getTargetId($estimate, 'field_revision_of');

    // Always mark the saving entity as the current revision.
    $estimate->set('field_is_current_revision', 1);

    // Set revision number deterministically.
    if ($revision_of_id > 0) {
      // It's a revision: revision_number = max(existing in chain) + 1
      $max = $this->getMaxRevisionNumber($chain, $estimate->isNew() ? 0 : (int) $estimate->id());
      $estimate->set('field_revision_number', $max + 1);
    }
    else {
      // Not a revision: if empty or invalid, set to 1.
      $current = $this->getIntValue($estimate, 'field_revision_number');
      if ($current <= 0) {
        $estimate->set('field_revision_number', 1);
      }
    }

    // Flip all other estimates in the chain to non-current.
    $this->unsetOtherCurrents($chain, $estimate->isNew() ? 0 : (int) $estimate->id());

    unset($processing[$key]);
  }

  /**
   * Unset field_is_current_revision on any other estimates in the same chain.
   */
  private function unsetOtherCurrents(array $chain, int $exclude_estimate_id): void {
    $ids = $this->estimateStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_estimate_request', $chain['estimate_request_id'])
      ->condition('field_estimate_type', $chain['estimate_type_id'])
      ->condition('field_is_current_revision', 1)
      ->execute();

    if (empty($ids)) {
      return;
    }

    $changed = FALSE;
    $entities = $this->estimateStorage->loadMultiple($ids);

    foreach ($entities as $e) {
      $id = (int) $e->id();
      if ($exclude_estimate_id > 0 && $id === $exclude_estimate_id) {
        continue;
      }
      if ($e->hasField('field_is_current_revision')) {
        $e->set('field_is_current_revision', 0);
        $e->save();
        $changed = TRUE;
      }
    }

    if ($changed) {
      $this->logger->notice('Estimate revision chain enforced for request @rid / type @tid.', [
        '@rid' => $chain['estimate_request_id'],
        '@tid' => $chain['estimate_type_id'],
      ]);
    }
  }

  /**
   * Get max revision_number in the chain (excluding one estimate if needed).
   */
  private function getMaxRevisionNumber(array $chain, int $exclude_estimate_id): int {
    $query = $this->estimateStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_estimate_request', $chain['estimate_request_id'])
      ->condition('field_estimate_type', $chain['estimate_type_id']);

    if ($exclude_estimate_id > 0) {
      $query->condition('id', $exclude_estimate_id, '<>');
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return 0;
    }

    $entities = $this->estimateStorage->loadMultiple($ids);

    $max = 0;
    foreach ($entities as $e) {
      $n = $this->getIntValue($e, 'field_revision_number');
      if ($n > $max) {
        $max = $n;
      }
    }

    return $max;
  }

  private function getTargetId(EntityInterface $entity, string $field_name): int {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return 0;
    }
    return (int) ($entity->get($field_name)->target_id ?? 0);
  }

  private function getIntValue(EntityInterface $entity, string $field_name): int {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return 0;
    }
    return (int) ($entity->get($field_name)->value ?? 0);
  }

}
