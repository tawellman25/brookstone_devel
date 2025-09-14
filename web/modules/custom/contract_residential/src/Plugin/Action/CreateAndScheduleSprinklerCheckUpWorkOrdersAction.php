<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Creates multiple sprinkler check-up work orders and schedules them for May-September on Mondays.
 *
 * @Action(
 *   id = "create_and_schedule_sprinkler_check_up_work_orders_action",
 *   label = @Translation("Create and Schedule Sprinkler Check-up Work Orders (May-Sep)"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE,
 *   type = "contracts"
 * )
 */
class CreateAndScheduleSprinklerCheckUpWorkOrdersAction extends ViewsBulkOperationsActionBase {
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

      $sprinkler_check_up_id = $contract->get('field_irrigation_check_ups')->target_id;
      $sprinkler_check_up_section = $entity_type_manager->getStorage('contract_sections')->load($sprinkler_check_up_id);
      if (!$sprinkler_check_up_section) {
        $messenger->addError('Referenced Sprinkler Check-up entity not found.');
        return;
      }

      $frequency_term_id = $sprinkler_check_up_section->get('field_check_up_frequency')->target_id;
      $frequency_term = $entity_type_manager->getStorage('taxonomy_term')->load($frequency_term_id);
      if (!$frequency_term) {
        $messenger->addError('Frequency term not found for this check-up.');
        return;
      }

      $frequency = $frequency_term->getName();
      $year = date('Y');

      date_default_timezone_set('UTC'); // Set PHP timezone to UTC

      // Now ensure date calculations are in UTC
      $start_date = new DrupalDateTime('first Monday of May ' . $year, new \DateTimeZone('America/Denver'));
      $end_date = new DrupalDateTime('last Monday of September ' . $year, new \DateTimeZone('America/Denver'));

      $estimate_text = $sprinkler_check_up_section->get('field_estimate')->value;
      $contract_estimate = $this->parseEstimate($estimate_text);

      $property_id = $contract->get('field_property')->target_id;
      $property = $entity_type_manager->getStorage('properties')->load($property_id);
      if (!$property) {
        $messenger->addError('Referenced Property not found.');
        return;
      }

      $work_orders_created = 0;
      
      switch (strtolower($frequency)) {
        case 'weekly':
          $current_date = clone $start_date; // Start from the beginning of the period
          while ($current_date->getTimestamp() <= $end_date->getTimestamp()) {
            $this->createAndScheduleWorkOrder($entity_type_manager, $current_user, $property_id, $contract_id, $contract_estimate, $current_date->getTimestamp(), $work_orders_created, $sprinkler_check_up_section);
            $work_orders_created++;
            
            // Use DrupalDateTime for moving to next Monday
            $next_monday = clone $current_date;
            $next_monday->modify('next Monday');
            $current_date = $next_monday;
          }
          break;
        case 'bi-weekly':
          $months = ['May', 'June', 'July', 'August', 'September'];
          foreach ($months as $month) {
            // Use UTC for date calculations
            $first_monday = new DrupalDateTime('first Monday of ' . $month . ' ' . $year, 'UTC');
            $third_monday = new DrupalDateTime('third Monday of ' . $month . ' ' . $year, 'UTC');
            
            if ($first_monday->getTimestamp() <= $end_date->getTimestamp()) {
              $this->createAndScheduleWorkOrder($entity_type_manager, $current_user, $property_id, $contract_id, $contract_estimate, $first_monday->getTimestamp(), $work_orders_created, $sprinkler_check_up_section);
              $work_orders_created++;
            }
            
            if ($third_monday->getTimestamp() <= $end_date->getTimestamp()) {
              $this->createAndScheduleWorkOrder($entity_type_manager, $current_user, $property_id, $contract_id, $contract_estimate, $third_monday->getTimestamp(), $work_orders_created, $sprinkler_check_up_section);
              $work_orders_created++;
            }
          }
          break;
        case 'monthly':
          $current_date = clone $start_date;
          while ($current_date->getTimestamp() <= $end_date->getTimestamp()) {
            $this->createAndScheduleWorkOrder($entity_type_manager, $current_user, $property_id, $contract_id, $contract_estimate, $current_date->getTimestamp(), $work_orders_created, $sprinkler_check_up_section);
            $work_orders_created++;
            // Move to the first Monday of the next month in UTC
            $current_date->modify('first Monday of next month');
          }
          break;
        case 'mid season':
          $mid_season_date = new DrupalDateTime('first Monday of July ' . $year, 'UTC');
          if ($mid_season_date->getTimestamp() <= $end_date->getTimestamp()) {
            $this->createAndScheduleWorkOrder($entity_type_manager, $current_user, $property_id, $contract_id, $contract_estimate, $mid_season_date->getTimestamp(), $work_orders_created, $sprinkler_check_up_section);
            $work_orders_created++;
          }
          break;
        default:
          $messenger->addError('Unknown frequency type.');
          return;
      }

      if ($work_orders_created > 0) {
        $messenger->addMessage($this->t("@count Sprinkler System Check-Up work orders created and scheduled for Contract #@contract_id for May to September @year on Mondays.", [
          '@count' => $work_orders_created,
          '@contract_id' => $contract_id,
          '@year' => $year,
        ]));
      } else {
        $messenger->addStatus($this->t('No work orders were created or scheduled for this contract for May to September on Mondays.'));
      }
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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * @param int $property_id
   * @param int $contract_id
   * @param float $contract_estimate
   * @param int $schedule_date
   * @param int &$work_orders_created
   * @param \Drupal\Core\Entity\EntityInterface $sprinkler_check_up_section
   */
  protected function createAndScheduleWorkOrder($entity_type_manager, $current_user, $property_id, $contract_id, $contract_estimate, $schedule_date, &$work_orders_created, $sprinkler_check_up_section) {
    $year = date('Y', $schedule_date);
    $week_number = date('W', $schedule_date);

    // Create DrupalDateTime from timestamp
    $scheduleTimestamp = DrupalDateTime::createFromTimestamp($schedule_date);
    $scheduleTimestamp->setTimezone(new \DateTimeZone('UTC'));
    $scheduleTimestampFormated = $scheduleTimestamp->format('Y-m-d\TH:i:s');

    // Create a new Sprinkler Check-up Work Order
    $work_order = $entity_type_manager->getStorage('work_order')->create([
      'type' => 'sprinkler_check_up',
      'uid' => $current_user->id(),
      'created' => time(),
      'field_service' => 393,
      'field_property' => $property_id,
      'field_contract' => $contract_id,
      'field_status' => 1089,
      'field_invoiced' => 0,
      'field_estimated_price' => $contract_estimate,
      'field_work_todo_description' => [
        'value' => "<p><b>$year - Week $week_number</b> - Sprinkler System Check-up.</p>",
        'format' => 'full_html',
      ],
    ]);
    $work_order->save();

    // Increment $work_orders_created once after work order is created
    $work_orders_created++;

    // Get the ID of the created work_order.
    $work_order_id = $work_order->id();

    // Add the work order ID to the multi-value field 'field_work_order'.
    $existing_work_orders = $sprinkler_check_up_section->get('field_work_order')->getValue();
    $existing_work_orders[] = ['target_id' => $work_order_id];
    $sprinkler_check_up_section->set('field_work_order', $existing_work_orders);
    $sprinkler_check_up_section->save();

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
      'field_scheduled_oder' => 10, // or use $work_orders_created if you want to keep it dynamic
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