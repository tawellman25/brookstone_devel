<?php

namespace Drupal\wo_deletion_manager;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

class WorkOrderDeletionManager {

  protected $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  public function deleteAssociatedTasks(EntityInterface $entity) {
    if ($entity->getEntityTypeId() !== 'work_order') {
      return;
    }

    // Define entity types and their corresponding reference field names
    $entity_types_to_delete = [
      'wo_complete_info' => 'field_work_order',
      'wo_chemicals_used' => 'field_work_order',
      'wo_tasks_list' => 'field_work_order',
      'wo_time_clock' => 'field_work_order',
      'wo_spraying_conditions' => 'field_work_order',
      'wo_material_dumping' => 'field_work_order',
      'wo_rental_equipment' => 'field_rented_for',
      'wo_status_updates' => 'field_status_of_wo',
      'wo_material_list' => ['field_work_order', ['wo_material_list_item', 'field_list_id']], // wo_material_list with children of type wo_material_list_item
      // Add more entity types here as needed
    ];

    foreach ($entity_types_to_delete as $entity_type => $field_names) {
        if (!is_array($field_names)) {
          $field_names = [$field_names];
        }
  
        // Delete the entities directly linked to work_order
        $this->deleteReferencedEntities($entity_type, $field_names, $entity->id());
      }
    }
  
    protected function deleteReferencedEntities($entity_type, array $field_names, $work_order_id) {
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $entities = $storage->loadByProperties([$field_names[0] => $work_order_id]);
  
      foreach ($entities as $entity) {
        $entity->delete();
        // If there are more fields (like child references), handle them here
        if (count($field_names) > 1 && is_array($field_names[1])) {
          list($child_entity_type, $child_field_name) = $field_names[1];
          $this->deleteChildEntities($child_entity_type, $child_field_name, $entity->id());
        }
      }
    }
  
    protected function deleteChildEntities($child_entity_type, $field_name, $parent_id) {
      $storage = $this->entityTypeManager->getStorage($child_entity_type);
      $child_entities = $storage->loadByProperties([$field_name => $parent_id]);
  
      foreach ($child_entities as $child_entity) {
        $child_entity->delete();
      }
    }
  }