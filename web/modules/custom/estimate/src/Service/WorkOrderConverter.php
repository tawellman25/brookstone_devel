<?php

declare(strict_types=1);

namespace Drupal\estimate\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\estimate\Exception\EstimateConversionException;
use Psr\Log\LoggerInterface;

/**
 * Converts an accepted/current Estimate into a Work Order deterministically.
 *
 * Guardrails:
 * - Stage must be Accepted (from estimate.settings.accepted_stage_tid).
 * - Estimate must be current revision (field_is_current_revision = TRUE).
 * - Estimate must not already link to a Work Order (field_work_order empty).
 * - No existing Work Order may already reference this Estimate (field_estimate).
 * - Target work order bundle must match estimate bundle and must not be 'estimate'.
 * - Target Work Order bundle must contain required fields: field_estimate, field_contact.
 *
 * Mapping:
 * - work_order.bundle = estimate.bundle
 * - work_order.field_estimate = estimate
 * - work_order.field_contact = estimate_request.field_contact (REQUIRED)
 * - work_order.field_contract = estimate_request.field_contract (if present)
 * - work_order.field_property = estimate_request.field_property (if present)
 * - work_order.field_service = estimate_request.field_service (if present)
 * - work_order.field_estimated_price = estimate.field_estimate_total (if present)
 * - estimate.field_work_order = created work order
 */
final class WorkOrderConverter {

  private EntityStorageInterface $estimateStorage;
  private EntityStorageInterface $estimateRequestStorage;
  private EntityStorageInterface $workOrderStorage;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {
    $this->estimateStorage = $this->entityTypeManager->getStorage('estimate');
    $this->estimateRequestStorage = $this->entityTypeManager->getStorage('estimate_request');
    $this->workOrderStorage = $this->entityTypeManager->getStorage('work_order');
  }

  /**
   * Convert an Estimate into a Work Order.
   *
   * @throws \Drupal\estimate\Exception\EstimateConversionException
   */
  public function convert(EntityInterface $estimate): EntityInterface {
    $this->assertIsEstimateEntity($estimate);

    $bundle = $estimate->bundle();
    if ($bundle === 'estimate') {
      throw new EstimateConversionException('Legacy work_order bundle "estimate" is deprecated and may not be used.');
    }

    // Gate: Accepted stage.
    $accepted_tid = (int) $this->configFactory->get('estimate.settings')->get('accepted_stage_tid');
    if ($accepted_tid <= 0) {
      throw new EstimateConversionException('estimate.settings.accepted_stage_tid is not configured.');
    }
    $stage_tid = $this->getTargetId($estimate, 'field_stage');
    if ($stage_tid !== $accepted_tid) {
      throw new EstimateConversionException(sprintf('Estimate must be in Accepted stage (%d). Current: %d', $accepted_tid, $stage_tid));
    }

    // Gate: Current revision.
    $is_current = (bool) ($this->getValue($estimate, 'field_is_current_revision') ?? FALSE);
    if (!$is_current) {
      throw new EstimateConversionException('Only the current revision may be converted to a Work Order.');
    }

    // Gate: Estimate not already linked to a Work Order.
    $existing_wo_id = $this->getTargetId($estimate, 'field_work_order');
    if ($existing_wo_id) {
      throw new EstimateConversionException(sprintf('Estimate already links to Work Order ID %d.', $existing_wo_id));
    }

    // Gate: No Work Order already references this Estimate.
    $already = $this->workOrderStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('field_estimate', (int) $estimate->id())
      ->range(0, 1)
      ->execute();
    if (!empty($already)) {
      $wo_id = (int) array_key_first($already);
      throw new EstimateConversionException(sprintf('A Work Order (%d) already references this Estimate.', $wo_id));
    }

    // Load Estimate Request (required).
    $estimate_request_id = $this->getTargetId($estimate, 'field_estimate_request');
    if (!$estimate_request_id) {
      throw new EstimateConversionException('Estimate is missing field_estimate_request.');
    }
    $estimate_request = $this->estimateRequestStorage->load($estimate_request_id);
    if (!$estimate_request) {
      throw new EstimateConversionException(sprintf('Estimate Request %d could not be loaded.', $estimate_request_id));
    }

    // Required: Contact must be present on the request (WO requires it).
    $contact_id = $this->getTargetId($estimate_request, 'field_contact');
    if ($contact_id <= 0) {
      throw new EstimateConversionException('Estimate Request is missing field_contact. Link a Contact before converting.');
    }

    // Validate work_order bundle exists.
    $bundles = $this->entityTypeManager->getBundleInfo('work_order');
    if (!isset($bundles[$bundle])) {
      throw new EstimateConversionException(sprintf('Work Order bundle "%s" does not exist. Bundle mapping requires work_order.bundle == estimate.bundle.', $bundle));
    }

    // Create Work Order.
    $work_order = $this->workOrderStorage->create([
      'type' => $bundle,
      'title' => $this->buildWorkOrderTitle($estimate),
    ]);

    // Required fields on the WO bundle.
    $this->assertWorkOrderHasFields($work_order, ['field_estimate', 'field_contact']);

    // Required joins.
    $this->setEntityReference($work_order, 'field_estimate', (int) $estimate->id());
    $this->setEntityReference($work_order, 'field_contact', $contact_id);

    // Optional mappings if fields exist on target bundle.
    $this->setEntityReferenceTargetIdFrom($work_order, 'field_contract', $estimate_request, 'field_contract');
    $this->setEntityReferenceTargetIdFrom($work_order, 'field_property', $estimate_request, 'field_property');
    $this->setEntityReferenceTargetIdFrom($work_order, 'field_service', $estimate_request, 'field_service');

    // Estimated price mirror (if the field exists).
    if ($work_order->hasField('field_estimated_price')) {
      $estimated_total = (string) ($this->getValue($estimate, 'field_estimate_total') ?? '0.00');
      $work_order->set('field_estimated_price', $estimated_total);
    }

    // Save WO then back-link estimate. Ensure failure recovery.
    try {
      $work_order->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Work Order creation failed for Estimate @eid: @msg', [
        '@eid' => $estimate->id(),
        '@msg' => $e->getMessage(),
      ]);
      throw new EstimateConversionException('Work Order creation failed: ' . $e->getMessage(), 0, $e);
    }

    try {
      $estimate->set('field_work_order', ['target_id' => (int) $work_order->id()]);
      $estimate->save();
    }
    catch (\Throwable $e) {
      // Avoid partial state: delete the WO if we cannot back-link the estimate.
      try {
        $this->workOrderStorage->delete([$work_order]);
      }
      catch (\Throwable $ignored) {
        // Log and rethrow the original.
        $this->logger->error('Failed to rollback Work Order @wid after Estimate back-link failure: @msg', [
          '@wid' => $work_order->id(),
          '@msg' => $ignored->getMessage(),
        ]);
      }

      $this->logger->error('Estimate back-link failed after creating Work Order @wid for Estimate @eid: @msg', [
        '@wid' => $work_order->id(),
        '@eid' => $estimate->id(),
        '@msg' => $e->getMessage(),
      ]);

      throw new EstimateConversionException('Estimate back-link failed after Work Order creation: ' . $e->getMessage(), 0, $e);
    }

    $this->logger->notice('Converted Estimate @eid (@bundle) to Work Order @wid.', [
      '@eid' => $estimate->id(),
      '@bundle' => $bundle,
      '@wid' => $work_order->id(),
    ]);

    return $work_order;
  }

  /**
   * Deterministic Work Order title.
   */
  private function buildWorkOrderTitle(EntityInterface $estimate): string {
    $estimate_title = (string) $estimate->label();
    if ($estimate_title !== '') {
      return 'WO: ' . $estimate_title;
    }
    return sprintf('WO: Estimate %d', (int) $estimate->id());
  }

  private function assertIsEstimateEntity(EntityInterface $estimate): void {
    if ($estimate->getEntityTypeId() !== 'estimate') {
      throw new EstimateConversionException('WorkOrderConverter expects an Estimate entity.');
    }
  }

  private function assertWorkOrderHasFields(EntityInterface $work_order, array $field_names): void {
    foreach ($field_names as $field_name) {
      if (!$work_order->hasField($field_name)) {
        throw new EstimateConversionException(sprintf('Work Order bundle "%s" is missing required field "%s".', $work_order->bundle(), $field_name));
      }
    }
  }

  private function getValue(EntityInterface $entity, string $field_name): mixed {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return NULL;
    }
    return $entity->get($field_name)->value;
  }

  private function getTargetId(EntityInterface $entity, string $field_name): int {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return 0;
    }
    return (int) ($entity->get($field_name)->target_id ?? 0);
  }

  private function setEntityReference(EntityInterface $entity, string $field_name, int $target_id): void {
    if (!$entity->hasField($field_name) || $target_id <= 0) {
      return;
    }
    $entity->set($field_name, ['target_id' => $target_id]);
  }

  private function setEntityReferenceTargetIdFrom(EntityInterface $target_entity, string $target_field, EntityInterface $source_entity, string $source_field): void {
    if (!$target_entity->hasField($target_field)) {
      return;
    }
    if (!$source_entity->hasField($source_field) || $source_entity->get($source_field)->isEmpty()) {
      return;
    }
    $tid = (int) ($source_entity->get($source_field)->target_id ?? 0);
    if ($tid > 0) {
      $target_entity->set($target_field, ['target_id' => $tid]);
    }
  }

}
