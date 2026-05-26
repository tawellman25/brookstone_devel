<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Form;

use Drupal\Core\Entity\EntityInterface;

/**
 * Shared helpers for the per-row dashboard operation forms.
 *
 * Each form (Link, Override, Confirm, Create, MarkReplacement, Reject,
 * SendToDiscovery) needs:
 *   - the row summary block at the top of the form
 *   - the IngestRow row_data array shape that PriceSyncService::ingestRow expects
 *   - the resolution-notes append helper used by every operation
 *
 * Extracting these once removes ~30 lines of duplication per form.
 */
trait IngestRowFormTrait {

  /**
   * Render a context block at the top of the form so the reviewer
   * sees what they're acting on before they pick / confirm / submit.
   */
  protected function buildRowSummary(EntityInterface $row): array {
    $batch = $row->get('field_batch')->entity;
    $supplier = $batch ? $batch->get('field_supplier')->entity : NULL;
    return [
      '#type' => 'item',
      '#title' => $this->t('Row'),
      '#markup' => $this->t(
        '<strong>Batch:</strong> @batch — <strong>Supplier:</strong> @supplier — <strong>Row #</strong>@n<br><strong>Description:</strong> <em>@desc</em><br><strong>SKU:</strong> @sku — <strong>Mfr Item #:</strong> @mfr<br><strong>Unit Cost:</strong> $@cost / @uom',
        [
          '@batch' => $batch ? $batch->label() : '(unknown)',
          '@supplier' => $supplier ? $supplier->label() : '(unknown)',
          '@n' => (int) ($row->get('field_row_number')->value ?? 0),
          '@desc' => (string) ($row->get('field_description')->value ?? ''),
          '@sku' => (string) ($row->get('field_supplier_sku')->value ?? '—'),
          '@mfr' => (string) ($row->get('field_manufacturer_item_number')->value ?? '—'),
          '@cost' => (string) ($row->get('field_unit_cost')->value ?? '0'),
          '@uom' => (string) ($row->get('field_cost_uom')->value ?? '—'),
        ],
      ),
    ];
  }

  /**
   * Translate the ingest row's fields into the row_data array that
   * PriceSyncService::ingestRow() expects.
   */
  protected function buildRowData(EntityInterface $row): array {
    return [
      'unit_cost'                => (float) ($row->get('field_unit_cost')->value ?? 0),
      'cost_uom'                 => (string) ($row->get('field_cost_uom')->value ?? ''),
      'supplier_sku'             => (string) ($row->get('field_supplier_sku')->value ?? ''),
      'manufacturer_item_number' => (string) ($row->get('field_manufacturer_item_number')->value ?? ''),
      'manufacturer_name'        => (string) ($row->get('field_manufacturer_name')->value ?? ''),
      'pack_quantity'            => (string) ($row->get('field_pack_quantity')->value ?? ''),
      'description'              => (string) ($row->get('field_description')->value ?? ''),
    ];
  }

  /**
   * Append a line to field_resolution_notes without clobbering prior
   * content (matcher score breakdown, parser note, etc.).
   */
  protected function appendNote(EntityInterface $row, string $line): void {
    $existing = trim((string) ($row->get('field_resolution_notes')->value ?? ''));
    $row->set(
      'field_resolution_notes',
      $existing === '' ? $line : ($existing . "\n" . $line),
    );
  }

}
