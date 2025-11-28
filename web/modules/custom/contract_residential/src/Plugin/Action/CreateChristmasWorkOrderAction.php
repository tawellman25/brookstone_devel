<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Creates and schedules Christmas decorations work orders.
 *
 * @Action(
 *   id = "create_christmas_work_orders_action",
 *   label = @Translation("Create Christmas Decorations Work Orders"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class CreateChristmasWorkOrderAction extends ViewsBulkOperationsActionBase {
  use MessengerTrait;

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if ($entity && $entity->getEntityTypeId() === 'contracts') {
      $entity_type_manager = \Drupal::service('entity_type.manager');
      $messenger = \Drupal::messenger();
      $current_user = \Drupal::currentUser();

      $contract_id = $entity->id();
      $contract = $entity_type_manager->getStorage('contracts')->load($contract_id);
      if (!$contract) {
        $messenger->addError('Contract not found.');
        return;
      }

      $christmas_decorations_id = $contract->get('field_christmas_decorations')->target_id;
      $christmas_decorations_section = $entity_type_manager->getStorage('contract_sections')->load($christmas_decorations_id);
      if (!$christmas_decorations_section) {
        $messenger->addError('Referenced Christmas Decorations entity not found.');
        return;
      }

      $estimate_text = $christmas_decorations_section->get('field_estimate')->value;
      $contract_estimate = $this->parseEstimate($estimate_text);

      $property_id = $contract->get('field_property')->target_id;
      $property = $entity_type_manager->getStorage('properties')->load($property_id);
      if (!$property) {
        $messenger->addError('Referenced Property not found.');
        return;
      }

      $year = date('Y');

      // Schedule for the week before Thanksgiving (USA)
      $thanksgivingWeekStart = new DrupalDateTime('fourth thursday of november ' . $year . ' -3 days', new \DateTimeZone('America/Denver'));
      $this->createAndScheduleWorkOrder($entity_type_manager, $current_user, $property_id, $contract_id, $contract_estimate, $thanksgivingWeekStart, $christmas_decorations_section, 'Hang Christmas Decorations');

      // Schedule for the first Monday after January 1st
      $newYearFirstMonday = new DrupalDateTime('first monday of january ' . ($year + 1), new \DateTimeZone('America/Denver'));
      $this->createAndScheduleWorkOrder($entity_type_manager, $current_user, $property_id, $contract_id, 0, $newYearFirstMonday, $christmas_decorations_section, 'Remove Christmas Decorations');

      $messenger->addMessage($this->t("Christmas Decorations work orders created and scheduled for Contract #@contract_id.", [
        '@contract_id' => $contract_id,
      ]));
    }
  }

  /**
   * Parses the estimate text into a float value.
   *
   * @param string $estimate_text The text to parse for an estimate.
   * @return float The parsed estimate value.
   */
  protected function parseEstimate($estimate_text) {
    if ($estimate_text === null) {
      return 0.0;
    }

    if (strpos($estimate_text, '-') !== false) {
      preg_match('/(\d+)\s*-\s*(\d+)/', $estimate_text, $matches);
      return !empty($matches) ? (float) $matches[2] : 0.0;
    } else {
      return (float) $estimate_text;
    }
  }

  /**
   * Helper method to create and schedule a work order and update the contract section.
   *
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param AccountInterface $current_user
   * @param int $property_id
   * @param int $contract_id
   * @param float $contract_estimate
   * @param DrupalDateTime $schedule_date
   * @param EntityInterface $christmas_decorations_section
   * @param string $description
   */
  protected function createAndScheduleWorkOrder($entity_type_manager, $current_user, $property_id, $contract_id, $contract_estimate, $schedule_date, $christmas_decorations_section, $description) {
    $scheduleTimestamp = $schedule_date->getTimestamp();
    $scheduleTimestampFormated = $schedule_date->format('Y-m-d\TH:i:s');

    // Create a new Christmas Decorations Work Order
    $work_order = $entity_type_manager->getStorage('work_order')->create([
      'type' => 'christmas_decorations',
      'uid' => $current_user->id(),
      'created' => time(),
      'field_service' => 396,
      'field_property' => $property_id,
      'field_contract' => $contract_id,
      'field_status' => 1089,
      'field_invoiced' => 0,
      'field_estimated_price' => $contract_estimate,
      'field_work_todo_description' => [
        'value' => "<p>" . date('Y', $scheduleTimestamp) . " - " . $description . "</p>",
        'format' => 'full_html',
      ],
    ]);
    $work_order->save();

    $work_order_id = $work_order->id();

    // Add the work order ID to the multi-value field 'field_work_order'.
    $existing_work_orders = $christmas_decorations_section->get('field_work_order')->getValue();
    $existing_work_orders[] = ['target_id' => $work_order_id];
    $christmas_decorations_section->set('field_work_order', $existing_work_orders);
    $christmas_decorations_section->save();

    // Create a scheduling entity for this work order
    $scheduling = $entity_type_manager->getStorage('scheduling')->create([
      'type' => 'work_order',
      'field_work_order' => ['target_id' => $work_order_id],
      'field_scheduled_firm' => FALSE,
      'field_scheduled' => TRUE,
      'field_scheduled_date_and_time' => [
        'value' => $scheduleTimestampFormated,
      ],
      'field_scheduling_note' => 'Automatically Scheduled by System',
      'field_scheduled_order' => 10,
    ]);
    $scheduling->save();
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }
}