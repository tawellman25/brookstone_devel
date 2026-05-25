<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Thin add-form controllers for the three single-bundle ECK entity
 * types this module owns.
 *
 * Why these exist:
 *   ECK's native add route is /admin/content/{eck_entity_type}/add/
 *   {eck_entity_bundle} (see eck.routing.yml). Both placeholders are
 *   required because ECK is designed for multi-bundle entities. The
 *   `_entity_form` controller (Drupal core) refuses to operate on an
 *   ECK entity without a bundle, throwing "Missing bundle for entity
 *   type X" — which is the bug that landed us here.
 *
 *   For these three entities each has exactly one bundle (`config`,
 *   `batch`, `row`), so putting the bundle in the URL would be pure
 *   noise. Each method below mirrors EckContentController::add for
 *   its specific entity/bundle pair, behind a clean URL.
 *
 * If a second bundle ever gets added to one of these entity types,
 * replace the dedicated method with the native ECK route pattern
 * (path: /.../add/{eck_entity_bundle}, controller:
 * EckContentController::add). Until then, this is the least-surprise
 * approach for single-bundle ECK entities in BOS.
 */
class AddRouteController extends ControllerBase {

  /**
   * Add form for supplier_ingest_config (bundle: config).
   *
   * Mirrors EckContentController::add for this specific entity/bundle.
   */
  public function addConfig(): array {
    return $this->buildAddForm('supplier_ingest_config', 'config');
  }

  /**
   * Add form for supplier_price_ingest_batch (bundle: batch).
   *
   * Preemptive — Phase 3.7 office manager dashboards may need to
   * create batches via UI flows that don't go through the upload form.
   */
  public function addBatch(): array {
    return $this->buildAddForm('supplier_price_ingest_batch', 'batch');
  }

  /**
   * Add form for supplier_price_ingest_row (bundle: row).
   *
   * Preemptive — rows are normally parser-created, but Phase 3.7
   * discovery/fuzzy resolution flows may need ad-hoc row creation.
   */
  public function addRow(): array {
    return $this->buildAddForm('supplier_price_ingest_row', 'row');
  }

  /**
   * Shared implementation — verbatim mirror of the relevant lines from
   * EckContentController::add. Keeps the logic in one place.
   */
  private function buildAddForm(string $entityTypeId, string $bundle): array {
    $entity = $this->entityTypeManager()
      ->getStorage($entityTypeId)
      ->create(['type' => $bundle]);
    return $this->entityFormBuilder()->getForm($entity);
  }

}
