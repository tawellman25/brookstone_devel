<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;

/**
 * Phase 3.2 placeholder view for a supplier_price_ingest_batch.
 *
 * Renders a minimal admin page showing the batch's key metadata,
 * its row count, and a sample of its parsed rows. Replaced by the
 * full dry-run review UI in Phase 3.5.
 *
 * Route: /admin/materials/supplier-ingest/batch/{supplier_price_ingest_batch}
 * Permission: 'administer supplier price ingest'
 *
 * Uses ControllerBase::entityTypeManager() rather than constructor
 * injection — ControllerBase already provides the dependency and
 * declaring our own readonly $entityTypeManager collides with the
 * parent's non-readonly property in PHP 8.2+.
 */
class BatchPlaceholderController extends ControllerBase {

  /**
   * Page callback.
   */
  public function view(EntityInterface $supplier_price_ingest_batch): array {
    $batch = $supplier_price_ingest_batch;

    $supplier = $batch->get('field_supplier')->entity;
    $file = $batch->get('field_source_file')->entity;
    $uploadedBy = $batch->get('field_uploaded_by')->entity;

    $statusValue = $batch->get('field_status')->value ?? '';

    $rowStorage = $this->entityTypeManager()->getStorage('supplier_price_ingest_row');
    $rowCount = (int) $rowStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_batch', $batch->id())
      ->count()
      ->execute();

    $erroredCount = (int) $rowStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_batch', $batch->id())
      ->condition('field_match_tier', 'error')
      ->count()
      ->execute();

    $sampleRows = $rowStorage->loadByProperties(['field_batch' => $batch->id()]);
    $sampleRows = array_slice($sampleRows, 0, 10, TRUE);

    $sampleRowsRender = [];
    foreach ($sampleRows as $row) {
      $sampleRowsRender[] = [
        'row_number' => (int) ($row->get('field_row_number')->value ?? 0),
        'supplier_sku' => (string) ($row->get('field_supplier_sku')->value ?? ''),
        'mfr_item' => (string) ($row->get('field_manufacturer_item_number')->value ?? ''),
        'description' => (string) ($row->get('field_description')->value ?? ''),
        'unit_cost' => (string) ($row->get('field_unit_cost')->value ?? ''),
        'cost_uom' => (string) ($row->get('field_cost_uom')->value ?? ''),
        'match_tier' => (string) ($row->get('field_match_tier')->value ?? ''),
        'resolution_notes' => (string) ($row->get('field_resolution_notes')->value ?? ''),
      ];
    }

    return [
      '#theme' => 'supplier_price_ingest_batch_placeholder',
      '#batch' => [
        'id' => (int) $batch->id(),
        'title' => $batch->label(),
        'status' => $statusValue,
        'supplier' => $supplier ? $supplier->label() : '(missing)',
        'source_filename' => (string) ($batch->get('field_source_filename')->value ?? ''),
        'source_file_url' => $file ? \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri()) : NULL,
        'uploaded_by' => $uploadedBy ? $uploadedBy->getDisplayName() : '(unknown)',
        'uploaded_on' => (string) ($batch->get('field_uploaded_on')->value ?? ''),
        'row_count_total' => (int) ($batch->get('field_row_count_total')->value ?? 0),
        'row_count_skipped' => (int) ($batch->get('field_row_count_skipped')->value ?? 0),
        'actual_row_count' => $rowCount,
        'errored_count' => $erroredCount,
        'notes' => (string) ($batch->get('field_notes')->value ?? ''),
        'dry_run_report' => (string) ($batch->get('field_dry_run_report')->value ?? ''),
      ],
      '#rows' => $sampleRowsRender,
      '#cache' => [
        'tags' => $batch->getCacheTags(),
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Title callback.
   */
  public function title(EntityInterface $supplier_price_ingest_batch): string {
    return (string) ($supplier_price_ingest_batch->label() ?? 'Supplier Price Ingest Batch');
  }

}
