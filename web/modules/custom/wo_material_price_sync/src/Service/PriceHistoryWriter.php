<?php

declare(strict_types=1);

namespace Drupal\wo_material_price_sync\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Single-purpose helper that writes append-only material_price_history
 * entries.
 *
 * Always populates:
 *  - field_user      = current user
 *  - field_wo_reference = the WO id when WO-driven (NULL for manual paths)
 *  - field_supplier_invoice_number = passed in (may be NULL)
 *  - title           = auto-generated short summary
 *
 * Never updates an existing row — append-only by design.
 */
final class PriceHistoryWriter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Write one history entry.
   *
   * @param int         $material_id      Material entity id.
   * @param int         $supplier_id      Supplier entity id.
   * @param float|null  $old_cost         Prior unit cost; NULL for first/auto_created.
   * @param float       $new_cost         The cost being recorded.
   * @param float|null  $delta_percent    Computed delta percent; NULL when no baseline.
   * @param string      $source           wo_entry|manual|invoice|auto_created
   * @param string      $status           applied|flagged_high|auto_created|approved|rejected|resolved
   * @param int|null    $wo_id            WO id when WO-driven; NULL otherwise.
   * @param string|null $invoice_number   Vendor invoice/receipt number; NULL when not provided.
   * @param string|null $change_notes     Free-text context for the entry.
   */
  public function write(
    int $material_id,
    int $supplier_id,
    ?float $old_cost,
    float $new_cost,
    ?float $delta_percent,
    string $source,
    string $status,
    ?int $wo_id = NULL,
    ?string $invoice_number = NULL,
    ?string $change_notes = NULL,
  ): bool {
    try {
      $title = $this->buildTitle($material_id, $supplier_id, $delta_percent);

      $values = [
        'type' => 'entry',
        'title' => $title,
        'field_material' => ['target_id' => $material_id],
        'field_supplier' => ['target_id' => $supplier_id],
        'field_new_cost' => $new_cost,
        'field_source' => $source,
        'field_status' => $status,
        'field_user' => ['target_id' => (int) $this->currentUser->id()],
      ];

      if ($old_cost !== NULL) {
        $values['field_old_cost'] = $old_cost;
      }
      if ($delta_percent !== NULL) {
        $values['field_delta_percent'] = round($delta_percent, 2);
      }
      if ($wo_id !== NULL && $wo_id > 0) {
        $values['field_wo_reference'] = ['target_id' => $wo_id];
      }
      if ($invoice_number !== NULL && $invoice_number !== '') {
        $values['field_supplier_invoice_number'] = $invoice_number;
      }
      if ($change_notes !== NULL && $change_notes !== '') {
        $values['field_change_notes'] = $change_notes;
      }

      $entry = $this->entityTypeManager->getStorage('material_price_history')->create($values);
      $entry->save();
      return TRUE;
    }
    catch (\Throwable $e) {
      \Drupal::logger('wo_material_price_sync')->error(
        'PriceHistoryWriter::write() failed for material @m / vendor @v: @msg',
        [
          '@m' => $material_id,
          '@v' => $supplier_id,
          '@msg' => $e->getMessage(),
        ]
      );
      return FALSE;
    }
  }

  /**
   * Builds a short summary title for the entry. Format examples:
   *   "Material #1234 ↔ Supplier #56 — +5.4%"
   *   "Material #1234 ↔ Supplier #56 — first cost"
   */
  private function buildTitle(int $material_id, int $supplier_id, ?float $delta_percent): string {
    $base = "Material #{$material_id} ↔ Supplier #{$supplier_id}";
    if ($delta_percent === NULL) {
      return $base . ' — first cost';
    }
    $sign = $delta_percent >= 0 ? '+' : '';
    return $base . ' — ' . $sign . number_format($delta_percent, 1) . '%';
  }

}
