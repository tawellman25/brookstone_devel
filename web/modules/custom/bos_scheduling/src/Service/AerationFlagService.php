<?php

namespace Drupal\bos_scheduling\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Manages the Aeration Flag Heads flag on sprinkler_start_up Work Orders.
 */
class AerationFlagService {

  protected Connection $database;
  protected EntityTypeManagerInterface $entityTypeManager;

  const ACTIVE_STATUSES = [1089, 1099, 1095, 1503, 1091, 1090, 1092, 1093, 1094, 1096];
  const DONE_STATUSES   = [1097, 1283, 1281, 1504, 1098];

  public function __construct(Connection $database, EntityTypeManagerInterface $entityTypeManager) {
    $this->database          = $database;
    $this->entityTypeManager = $entityTypeManager;
  }

  public function updateStartUpFlag(int $wo_id, int $property_id): void {
    $has_aeration = $this->propertyHasActiveAerationWo($property_id);
    $exists = $this->database->select('work_order__field_aeration_flag_heads', 'f')
      ->fields('f', ['entity_id'])
      ->condition('entity_id', $wo_id)
      ->execute()->fetchField();
    if ($exists) {
      $this->database->update('work_order__field_aeration_flag_heads')
        ->fields(['field_aeration_flag_heads_value' => (int) $has_aeration])
        ->condition('entity_id', $wo_id)
        ->execute();
    }
    else {
      $this->database->insert('work_order__field_aeration_flag_heads')
        ->fields([
          'bundle'                          => 'sprinkler_start_up',
          'deleted'                         => 0,
          'entity_id'                       => $wo_id,
          'revision_id'                     => $wo_id,
          'langcode'                        => 'en',
          'delta'                           => 0,
          'field_aeration_flag_heads_value' => (int) $has_aeration,
        ])
        ->execute();
    }
  }

  public function updateStartUpsForProperty(int $property_id, bool $is_active): void {
    $query = $this->database->select('work_order', 'w');
    $query->fields('w', ['id']);
    $query->join('work_order__field_property', 'wop', 'wop.entity_id = w.id AND wop.deleted = 0');
    $query->join('work_order__field_status', 'wos', 'wos.entity_id = w.id AND wos.deleted = 0');
    $query->condition('w.type', 'sprinkler_start_up');
    $query->condition('wop.field_property_target_id', $property_id);
    $query->condition('wos.field_status_target_id', self::ACTIVE_STATUSES, 'IN');
    $wo_ids = $query->execute()->fetchCol();
    if (empty($wo_ids)) return;
    foreach ($wo_ids as $wo_id) {
      $exists = $this->database->select('work_order__field_aeration_flag_heads', 'f')
        ->fields('f', ['entity_id'])
        ->condition('entity_id', $wo_id)
        ->execute()->fetchField();
      if ($exists) {
        $this->database->update('work_order__field_aeration_flag_heads')
          ->fields(['field_aeration_flag_heads_value' => (int) $is_active])
          ->condition('entity_id', $wo_id)
          ->execute();
      }
      else {
        $this->database->insert('work_order__field_aeration_flag_heads')
          ->fields([
            'bundle'                          => 'sprinkler_start_up',
            'deleted'                         => 0,
            'entity_id'                       => $wo_id,
            'revision_id'                     => $wo_id,
            'langcode'                        => 'en',
            'delta'                           => 0,
            'field_aeration_flag_heads_value' => (int) $is_active,
          ])
          ->execute();
      }
    }
  }

  public function propertyHasActiveAerationWo(int $property_id): bool {
    $query = $this->database->select('work_order', 'w');
    $query->fields('w', ['id']);
    $query->join('work_order__field_property', 'wop', 'wop.entity_id = w.id AND wop.deleted = 0');
    $query->join('work_order__field_status', 'wos', 'wos.entity_id = w.id AND wos.deleted = 0');
    $query->condition('w.type', 'aerating');
    $query->condition('wop.field_property_target_id', $property_id);
    $query->condition('wos.field_status_target_id', self::ACTIVE_STATUSES, 'IN');
    $query->range(0, 1);
    return (bool) $query->execute()->fetchField();
  }

  public function getPropertyId(int $wo_id): ?int {
    $result = $this->database->select('work_order__field_property', 'wop')
      ->fields('wop', ['field_property_target_id'])
      ->condition('wop.entity_id', $wo_id)
      ->condition('wop.deleted', 0)
      ->execute()->fetchField();
    return $result ? (int) $result : NULL;
  }

  public function getStatusTid(int $wo_id): ?int {
    $result = $this->database->select('work_order__field_status', 'wos')
      ->fields('wos', ['field_status_target_id'])
      ->condition('wos.entity_id', $wo_id)
      ->condition('wos.deleted', 0)
      ->execute()->fetchField();
    return $result ? (int) $result : NULL;
  }

}
