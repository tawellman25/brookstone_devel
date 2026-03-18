<?php

namespace Drupal\wo_shared\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Drush commands for wo_shared.
 */
class WoSharedCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a WoSharedCommands object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Backfill missing property_spraying_info records for all spray WOs.
   *
   * @command wo-shared:backfill-spraying-info
   * @aliases wo-bsi
   * @usage drush wo-shared:backfill-spraying-info
   *   Create missing property_spraying_info records for all spray-related work orders.
   */
  public function backfillSprayingInfo(): void {
    $map = _wo_shared_get_spraying_info_bundle_map();
    $total_created = 0;

    foreach ($map as $wo_bundle => $spraying_info_bundle) {
      if ($spraying_info_bundle === NULL) {
        $this->io()->text("Skipping $wo_bundle (no spraying_info bundle mapped).");
        continue;
      }

      $wo_ids = $this->entityTypeManager
        ->getStorage('work_order')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $wo_bundle)
        ->execute();

      if (empty($wo_ids)) {
        $this->io()->text("$wo_bundle: 0 WOs found, skipping.");
        continue;
      }

      $this->io()->text("$wo_bundle: checking " . count($wo_ids) . " work orders...");

      $bundle_created = 0;
      $seen_properties = [];

      $work_orders = $this->entityTypeManager
        ->getStorage('work_order')
        ->loadMultiple($wo_ids);

      foreach ($work_orders as $wo) {
        $property_id = $wo->get('field_property')->target_id ?? NULL;
        if (!$property_id) {
          continue;
        }

        // Skip if we already processed this property in this bundle.
        $key = $spraying_info_bundle . ':' . $property_id;
        if (isset($seen_properties[$key])) {
          continue;
        }
        $seen_properties[$key] = TRUE;

        $existing = $this->entityTypeManager
          ->getStorage('property_spraying_info')
          ->loadByProperties([
            'type' => $spraying_info_bundle,
            'field_property' => $property_id,
          ]);

        if (!empty($existing)) {
          continue;
        }

        $record = $this->entityTypeManager
          ->getStorage('property_spraying_info')
          ->create([
            'type' => $spraying_info_bundle,
            'field_property' => $property_id,
          ]);
        $record->save();

        $bundle_created++;
        $total_created++;
      }

      $this->io()->text("  → Created $bundle_created property_spraying_info:$spraying_info_bundle records.");
    }

    $this->io()->success("Backfill complete. Total records created: $total_created");
  }

}
