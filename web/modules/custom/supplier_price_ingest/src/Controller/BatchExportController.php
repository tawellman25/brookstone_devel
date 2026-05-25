<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 3.5 — CSV export for a supplier_price_ingest_batch.
 *
 * Route: /admin/materials/supplier-ingest/batch/{batch}/export.csv
 * Permission: 'administer supplier price ingest'
 *
 * Streams the response. Loads rows in chunks of 200 so memory stays
 * bounded regardless of batch size. Each row writes immediately so the
 * browser sees data flowing before the whole batch is iterated.
 */
class BatchExportController extends ControllerBase {

  /**
   * Row-loading chunk size for streaming.
   */
  private const CHUNK_SIZE = 200;

  /**
   * CSV column order. Matches the spec.
   */
  private const COLUMNS = [
    'row_number',
    'match_tier',
    'match_confidence',
    'row_status',
    'supplier_sku',
    'manufacturer_item_number',
    'manufacturer_name',
    'description',
    'unit_cost',
    'cost_uom',
    'pack_quantity',
    'matched_material_id',
    'matched_material_title',
    'existing_link_id',
    'resolution_notes',
  ];

  public function export(EntityInterface $supplier_price_ingest_batch): StreamedResponse {
    $batch = $supplier_price_ingest_batch;
    $batchId = (int) $batch->id();
    $filename = sprintf('batch-%d-%s.csv', $batchId, date('Y-m-d'));

    $response = new StreamedResponse();
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set(
      'Content-Disposition',
      sprintf('attachment; filename="%s"', $filename),
    );
    // Cache-Control matches the file-download convention used elsewhere
    // in BOS (e.g., admin-table exports).
    $response->headers->set('Cache-Control', 'no-store, max-age=0');

    $rowStorage = $this->entityTypeManager()->getStorage('supplier_price_ingest_row');
    $materialStorage = $this->entityTypeManager()->getStorage('material');

    $response->setCallback(function () use ($batchId, $rowStorage, $materialStorage): void {
      $out = fopen('php://output', 'wb');
      if ($out === FALSE) {
        // Streaming target unavailable — bail silently. The browser
        // will see an empty response which is the right failure mode
        // for an unrecoverable I/O error during a download.
        return;
      }
      // Header row.
      fputcsv($out, self::COLUMNS);

      $rowIds = $rowStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_batch', $batchId)
        ->sort('field_row_number', 'ASC')
        ->execute();
      if (empty($rowIds)) {
        fclose($out);
        return;
      }

      // Material-label cache scoped to this request. Avoids re-loading
      // the same material entity across rows in different chunks.
      $materialLabels = [];

      foreach (array_chunk(array_values($rowIds), self::CHUNK_SIZE) as $chunk) {
        $rows = $rowStorage->loadMultiple($chunk);
        foreach ($rows as $row) {
          $matchedId = (int) ($row->get('field_matched_material')->target_id ?? 0);
          $matchedTitle = '';
          if ($matchedId > 0) {
            if (!isset($materialLabels[$matchedId])) {
              $mat = $materialStorage->load($matchedId);
              $materialLabels[$matchedId] = $mat ? (string) $mat->label() : '';
            }
            $matchedTitle = $materialLabels[$matchedId];
          }
          $existingLinkId = $row->hasField('field_existing_link') && !$row->get('field_existing_link')->isEmpty()
            ? (int) $row->get('field_existing_link')->target_id
            : '';

          fputcsv($out, [
            (string) ($row->get('field_row_number')->value ?? ''),
            (string) ($row->get('field_match_tier')->value ?? ''),
            (string) ($row->get('field_match_confidence')->value ?? ''),
            (string) ($row->get('field_row_status')->value ?? ''),
            (string) ($row->get('field_supplier_sku')->value ?? ''),
            (string) ($row->get('field_manufacturer_item_number')->value ?? ''),
            (string) ($row->get('field_manufacturer_name')->value ?? ''),
            (string) ($row->get('field_description')->value ?? ''),
            (string) ($row->get('field_unit_cost')->value ?? ''),
            (string) ($row->get('field_cost_uom')->value ?? ''),
            (string) ($row->get('field_pack_quantity')->value ?? ''),
            $matchedId > 0 ? (string) $matchedId : '',
            $matchedTitle,
            $existingLinkId !== '' ? (string) $existingLinkId : '',
            (string) ($row->get('field_resolution_notes')->value ?? ''),
          ]);
        }
        $rowStorage->resetCache(array_keys($rows));
        // Flush the chunk to the wire so the browser sees progress.
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
          @ob_flush();
        }
        @flush();
      }

      fclose($out);
    });

    return $response;
  }

}
