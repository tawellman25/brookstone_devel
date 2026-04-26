<?php

declare(strict_types=1);

namespace Drupal\wo_material_price_sync\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Detects price changes on wo_material_list_item entries and syncs back to
 * material_suppliers with threshold guardrails.
 *
 * Decision tree (from spec — do not reorganize without spec sign-off):
 *
 * 1. Skip if WO is Complete (1097), no stocked material, or no cost entered.
 * 2. Detect price change:
 *    - new entity:   entered_cost vs material catalog cost
 *    - existing:     entered_cost vs $entity->original cost
 *    If unchanged, return silently (zero crew burden for untouched lines).
 * 3. Validate vendor (handled in validate() called from hook_entity_validate).
 * 4. Find or auto-create the material_suppliers row for (material, vendor):
 *    - No row exists      → auto_create, status=auto_created
 *    - Row exists, no prior cost → first cost recorded, status=applied
 *    - Row exists, baseline > 0 →
 *        delta_pct = (entered - baseline) / baseline * 100
 *        delta_pct >= +10.0  → flag_high (NO catalog update)
 *        delta_pct <  +10.0  → apply (catalog updates via material.module sync)
 * 5. Write a history row in every non-skip case.
 * 6. Never touch wo_material_list_item snapshot (field_material_cost) here.
 */
final class PriceSyncService {

  /**
   * WO status TID for "Complete" — pricing locked at this point.
   */
  private const WO_STATUS_COMPLETE = 1097;

  /**
   * Threshold (percent) above which a price increase is flagged for
   * Office Manager review and catalog update is held back.
   */
  private const THRESHOLD_PERCENT = 10.0;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly PriceHistoryWriter $historyWriter,
  ) {}

  /**
   * Validate that vendor is set when crew enters a non-catalog price.
   *
   * Called from hook_entity_validate(). Adds a constraint violation to
   * block the save with a clear inline error if vendor is missing.
   */
  public function validate(EntityInterface $entity, ConstraintViolationListInterface $violations): void {
    if (!$this->shouldProcess($entity)) {
      return;
    }
    if (!$this->hasPriceChanged($entity)) {
      return;
    }
    if (!$entity->hasField('field_purchased_supplier') || $entity->get('field_purchased_supplier')->isEmpty()) {
      $violations->add(new ConstraintViolation(
        'Bought From vendor is required when the material price is changed from catalog. Please select the vendor this material was purchased from.',
        '',
        [],
        $entity,
        'field_purchased_supplier',
        NULL
      ));
    }
  }

  /**
   * Process an inserted/updated wo_material_list_item.
   *
   * Called from hook_ENTITY_TYPE_insert() and hook_ENTITY_TYPE_update().
   */
  public function process(EntityInterface $entity): void {
    if (!$this->shouldProcess($entity)) {
      return;
    }
    if (!$this->hasPriceChanged($entity)) {
      return;
    }
    // Vendor required (validate() should have caught it; defensive guard).
    if (!$entity->hasField('field_purchased_supplier') || $entity->get('field_purchased_supplier')->isEmpty()) {
      return;
    }

    $material_id = (int) $entity->get('field_parts_used')->target_id;
    $vendor_id = (int) $entity->get('field_purchased_supplier')->target_id;
    $entered_cost = (float) $entity->get('field_material_cost')->value;

    $invoice_number = NULL;
    if ($entity->hasField('field_supplier_invoice_number') && !$entity->get('field_supplier_invoice_number')->isEmpty()) {
      $invoice_number = trim((string) $entity->get('field_supplier_invoice_number')->value);
      if ($invoice_number === '') {
        $invoice_number = NULL;
      }
    }

    $wo_id = $this->getWorkOrderId($entity);

    // Find existing material_suppliers row for this (material, vendor) pair.
    $ms_storage = $this->entityTypeManager->getStorage('material_suppliers');
    $ms_ids = $ms_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_material', $material_id)
      ->condition('field_supplier', $vendor_id)
      ->range(0, 1)
      ->execute();

    if (empty($ms_ids)) {
      $this->autoCreatePair($material_id, $vendor_id, $entered_cost, $invoice_number, $wo_id);
      return;
    }

    /** @var \Drupal\Core\Entity\EntityInterface $ms_row */
    $ms_row = $ms_storage->load(reset($ms_ids));
    if (!$ms_row) {
      $this->loggerFactory->get('wo_material_price_sync')
        ->warning('material_suppliers row id was returned by query but failed to load. Skipping sync for material @m / vendor @v.', [
          '@m' => $material_id,
          '@v' => $vendor_id,
        ]);
      return;
    }

    $baseline = NULL;
    if ($ms_row->hasField('field_supplier_unit_cost') && !$ms_row->get('field_supplier_unit_cost')->isEmpty()) {
      $raw = $ms_row->get('field_supplier_unit_cost')->value;
      if (is_numeric($raw)) {
        $baseline = (float) $raw;
      }
    }

    if ($baseline === NULL || $baseline <= 0.0) {
      $this->firstCostRecorded($ms_row, $entered_cost, $invoice_number, $wo_id);
      return;
    }

    $delta_pct = (($entered_cost - $baseline) / $baseline) * 100.0;

    if ($delta_pct >= self::THRESHOLD_PERCENT) {
      $this->flagHigh($ms_row, $baseline, $entered_cost, $delta_pct, $invoice_number, $wo_id);
      return;
    }

    $this->applyChange($ms_row, $baseline, $entered_cost, $delta_pct, $invoice_number, $wo_id);
  }

  // ── Skip / change-detection helpers ─────────────────────────────────

  /**
   * Returns TRUE when this entity is in scope for the price-sync flow.
   */
  private function shouldProcess(EntityInterface $entity): bool {
    if ($entity->getEntityTypeId() !== 'wo_material_list_item') {
      return FALSE;
    }
    if ($entity->bundle() !== 'items') {
      return FALSE;
    }
    if ($this->isWorkOrderComplete($entity)) {
      // Pricing locked once WO is Complete.
      return FALSE;
    }
    if (!$entity->hasField('field_parts_used') || $entity->get('field_parts_used')->isEmpty()) {
      // Purchased path (alternate name) handled differently; we only sync
      // stocked-material lines into the supplier catalog.
      return FALSE;
    }
    if (!$entity->hasField('field_material_cost') || $entity->get('field_material_cost')->isEmpty()) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Returns TRUE when this save represents a price change vs catalog (new)
   * or vs the prior persisted value (update).
   */
  private function hasPriceChanged(EntityInterface $entity): bool {
    $entered_cost = (float) $entity->get('field_material_cost')->value;

    if ($entity->isNew()) {
      $catalog_cost = $this->getCatalogCost($entity);
      if ($catalog_cost === NULL) {
        // No catalog cost to compare against — treat as a change so the
        // crew's entered value gets recorded as the first known cost for
        // the (material, vendor) pair.
        return TRUE;
      }
      return $this->floatNotEqual($entered_cost, $catalog_cost);
    }

    if (!isset($entity->original)) {
      return FALSE;
    }
    if (!$entity->original->hasField('field_material_cost') || $entity->original->get('field_material_cost')->isEmpty()) {
      // Was unset, now set → treat as change.
      return TRUE;
    }
    $original_cost = (float) $entity->original->get('field_material_cost')->value;
    return $this->floatNotEqual($entered_cost, $original_cost);
  }

  /**
   * Float comparison with tolerance for cent-level precision.
   */
  private function floatNotEqual(float $a, float $b): bool {
    return abs($a - $b) > 0.005;
  }

  /**
   * Resolve the catalog cost from the referenced material's
   * field_cost_integer. Returns NULL when not available.
   */
  private function getCatalogCost(EntityInterface $entity): ?float {
    $material = $entity->get('field_parts_used')->entity;
    if (!$material || !$material->hasField('field_cost_integer') || $material->get('field_cost_integer')->isEmpty()) {
      return NULL;
    }
    $raw = $material->get('field_cost_integer')->value;
    return is_numeric($raw) ? (float) $raw : NULL;
  }

  /**
   * Returns TRUE when the line item's parent WO is at status "Complete".
   */
  private function isWorkOrderComplete(EntityInterface $entity): bool {
    $wo_id = $this->getWorkOrderId($entity);
    if ($wo_id === NULL) {
      return FALSE;
    }
    try {
      $wo = $this->entityTypeManager->getStorage('work_order')->load($wo_id);
    }
    catch (\Throwable $e) {
      return FALSE;
    }
    if (!$wo || !$wo->hasField('field_status') || $wo->get('field_status')->isEmpty()) {
      return FALSE;
    }
    return (int) $wo->get('field_status')->target_id === self::WO_STATUS_COMPLETE;
  }

  /**
   * Walks line item → wo_material_list → work_order to find the WO id.
   */
  private function getWorkOrderId(EntityInterface $entity): ?int {
    if (!$entity->hasField('field_list_id') || $entity->get('field_list_id')->isEmpty()) {
      return NULL;
    }
    $list = $entity->get('field_list_id')->entity;
    if (!$list || !$list->hasField('field_work_order') || $list->get('field_work_order')->isEmpty()) {
      return NULL;
    }
    return (int) $list->get('field_work_order')->target_id;
  }

  // ── Branch handlers (one per terminal state in the decision tree) ───

  /**
   * Auto-create a new material_suppliers row from this WO entry.
   * History status: auto_created.
   */
  private function autoCreatePair(int $material_id, int $vendor_id, float $entered_cost, ?string $invoice_number, ?int $wo_id): void {
    $today = date('Y-m-d', $this->time->getRequestTime());
    $username = $this->currentUser->getDisplayName();

    $price_notes = "Auto-created from WO #{$wo_id} by {$username} on {$today}";
    if ($invoice_number !== NULL) {
      $price_notes .= " — invoice #{$invoice_number}";
    }

    try {
      $row = $this->entityTypeManager->getStorage('material_suppliers')->create([
        'type' => 'supplier',
        'field_material' => ['target_id' => $material_id],
        'field_supplier' => ['target_id' => $vendor_id],
        'field_supplier_unit_cost' => $entered_cost,
        'field_price_effective_date' => $today,
        'field_price_source' => 'wo_entry',
        'field_price_notes' => $price_notes,
      ]);
      // Saving fires material.module's MAX-cost auto-sync.
      $row->save();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('wo_material_price_sync')
        ->error('Auto-create material_suppliers failed for material @m / vendor @v: @msg', [
          '@m' => $material_id,
          '@v' => $vendor_id,
          '@msg' => $e->getMessage(),
        ]);
      return;
    }

    $context = "New (material, vendor) pairing. Auto-created from WO #{$wo_id}.";
    $context .= $invoice_number !== NULL
      ? " Invoice #{$invoice_number}."
      : ' No invoice number provided.';

    $this->historyWriter->write(
      material_id: $material_id,
      supplier_id: $vendor_id,
      old_cost: NULL,
      new_cost: $entered_cost,
      delta_percent: NULL,
      source: 'auto_created',
      status: 'auto_created',
      wo_id: $wo_id,
      invoice_number: $invoice_number,
      change_notes: $context,
    );
  }

  /**
   * Existing pair but no prior cost — record the entered cost as the first
   * known cost. History status: applied.
   */
  private function firstCostRecorded(EntityInterface $ms_row, float $entered_cost, ?string $invoice_number, ?int $wo_id): void {
    $today = date('Y-m-d', $this->time->getRequestTime());

    $ms_row->set('field_supplier_unit_cost', $entered_cost);
    $ms_row->set('field_price_effective_date', $today);
    $ms_row->set('field_price_source', 'wo_entry');
    try {
      $ms_row->save();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('wo_material_price_sync')
        ->error('Update material_suppliers row @id failed: @msg', [
          '@id' => $ms_row->id(),
          '@msg' => $e->getMessage(),
        ]);
      return;
    }

    $notes = 'First cost recorded for this pairing.';
    if ($invoice_number !== NULL) {
      $notes .= " Invoice #{$invoice_number}.";
    }

    $this->historyWriter->write(
      material_id: (int) $ms_row->get('field_material')->target_id,
      supplier_id: (int) $ms_row->get('field_supplier')->target_id,
      old_cost: NULL,
      new_cost: $entered_cost,
      delta_percent: NULL,
      source: 'wo_entry',
      status: 'applied',
      wo_id: $wo_id,
      invoice_number: $invoice_number,
      change_notes: $notes,
    );
  }

  /**
   * Price increase exceeds threshold — flag for office review, catalog NOT
   * updated. History status: flagged_high.
   */
  private function flagHigh(EntityInterface $ms_row, float $baseline, float $entered_cost, float $delta_pct, ?string $invoice_number, ?int $wo_id): void {
    $delta_str = number_format($delta_pct, 1);
    $threshold_str = number_format(self::THRESHOLD_PERCENT, 0);

    $notes = "Price increase of {$delta_str}% exceeds {$threshold_str}% threshold. Catalog NOT updated. Office Manager review required.";
    if ($invoice_number !== NULL) {
      $notes .= " Invoice #{$invoice_number}.";
    }
    else {
      $notes .= ' NO invoice number provided — office should request from crew before approval.';
    }

    $this->historyWriter->write(
      material_id: (int) $ms_row->get('field_material')->target_id,
      supplier_id: (int) $ms_row->get('field_supplier')->target_id,
      old_cost: $baseline,
      new_cost: $entered_cost,
      delta_percent: $delta_pct,
      source: 'wo_entry',
      status: 'flagged_high',
      wo_id: $wo_id,
      invoice_number: $invoice_number,
      change_notes: $notes,
    );
  }

  /**
   * Apply the price change — within threshold or any decrease. Catalog
   * updates via material.module's MAX-sync on the row save.
   * History status: applied.
   */
  private function applyChange(EntityInterface $ms_row, float $baseline, float $entered_cost, float $delta_pct, ?string $invoice_number, ?int $wo_id): void {
    $today = date('Y-m-d', $this->time->getRequestTime());

    $existing_notes = '';
    if ($ms_row->hasField('field_price_notes') && !$ms_row->get('field_price_notes')->isEmpty()) {
      $existing_notes = trim((string) $ms_row->get('field_price_notes')->value);
    }
    $append = "Updated from WO #{$wo_id} on {$today}";
    if ($invoice_number !== NULL) {
      $append .= " — invoice #{$invoice_number}";
    }
    $combined_notes = $existing_notes !== '' ? $existing_notes . "\n" . $append : $append;

    $ms_row->set('field_supplier_unit_cost', $entered_cost);
    $ms_row->set('field_price_effective_date', $today);
    $ms_row->set('field_price_source', 'wo_entry');
    $ms_row->set('field_price_notes', $combined_notes);
    try {
      // Saving fires material.module's MAX-cost auto-sync, which propagates
      // the new cost to material.field_cost_integer (and field_installed_price)
      // when this vendor is the most expensive eligible source.
      $ms_row->save();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('wo_material_price_sync')
        ->error('Apply price update on material_suppliers row @id failed: @msg', [
          '@id' => $ms_row->id(),
          '@msg' => $e->getMessage(),
        ]);
      return;
    }

    $notes = "Updated from WO #{$wo_id}.";
    if ($invoice_number !== NULL) {
      $notes .= " Invoice #{$invoice_number}.";
    }

    $this->historyWriter->write(
      material_id: (int) $ms_row->get('field_material')->target_id,
      supplier_id: (int) $ms_row->get('field_supplier')->target_id,
      old_cost: $baseline,
      new_cost: $entered_cost,
      delta_percent: $delta_pct,
      source: 'wo_entry',
      status: 'applied',
      wo_id: $wo_id,
      invoice_number: $invoice_number,
      change_notes: $notes,
    );
  }

}
