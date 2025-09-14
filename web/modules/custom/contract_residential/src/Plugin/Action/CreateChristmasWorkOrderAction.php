<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Creates and schedules Christmas decorations work orders.
 *
 * @Action(
 *   id = "create_christmas_work_orders_action",
 *   label = @Translation("Create Christmas Decorations Work Orders"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE,
 *   type = "contracts"
 * )
 */
class CreateChristmasWorkOrderAction extends ViewsBulkOperationsActionBase {

  /**
   * Process only explicitly selected entities.
   */
  public function executeMultiple(array $entities) {
    if (empty($entities)) {
      \Drupal::messenger()->addError($this->t('No items selected.'));
      return;
    }

    $count = 0;
    foreach ($entities as $entity) {
      if ($entity instanceof EntityInterface && $entity->getEntityTypeId() === 'contracts') {
        $this->execute($entity);
        $count++;
      }
    }

    if ($count) {
      \Drupal::messenger()->addStatus($this->t('Processed @count item(s).', ['@count' => $count]));
    }
  }

  /**
   * Execute on a single Contract.
   */
  public function execute(EntityInterface $entity = NULL) {
    if (!$entity || $entity->getEntityTypeId() !== 'contracts') {
      return;
    }

    $etm = \Drupal::entityTypeManager();
    $messenger = \Drupal::messenger();
    $current_user = \Drupal::currentUser();

    $contract_id = $entity->id();
    $contract = $etm->getStorage('contracts')->load($contract_id);
    if (!$contract) {
      $messenger->addError($this->t('Contract not found.'));
      return;
    }

    $section_id = $contract->get('field_christmas_decorations')->target_id ?? NULL;
    if (!$section_id) {
      $messenger->addError($this->t('Referenced Christmas Decorations section is missing.'));
      return;
    }
    $section = $etm->getStorage('contract_sections')->load($section_id);
    if (!$section) {
      $messenger->addError($this->t('Referenced Christmas Decorations section not found.'));
      return;
    }

    $estimate_text = (string) ($section->get('field_estimate')->value ?? '');
    $contract_estimate = $this->parseEstimate($estimate_text);

    $property_id = $contract->get('field_property')->target_id ?? NULL;
    if (!$property_id) {
      $messenger->addError($this->t('Referenced Property is missing.'));
      return;
    }
    if (!$etm->getStorage('properties')->load($property_id)) {
      $messenger->addError($this->t('Referenced Property not found.'));
      return;
    }

    $year = date('Y');

    // Week before Thanksgiving (USA): 4th Thursday of Nov, minus 3 days (Monday).
    $thanksgiving_week_start = new DrupalDateTime('fourth thursday of november ' . $year . ' -3 days', new \DateTimeZone('America/Denver'));
    $this->createAndScheduleWorkOrder($etm, $current_user, $property_id, $contract_id, $contract_estimate, $thanksgiving_week_start, $section, 'Hang Christmas Decorations');

    // First Monday after Jan 1 next year.
    $first_monday_new_year = new DrupalDateTime('first monday of january ' . ($year + 1), new \DateTimeZone('America/Denver'));
    $this->createAndScheduleWorkOrder($etm, $current_user, $property_id, $contract_id, 0, $first_monday_new_year, $section, 'Remove Christmas Decorations');

    $messenger->addStatus($this->t('Christmas Decorations work orders created and scheduled for Contract #@id.', ['@id' => $contract_id]));
  }

  /**
   * Parse estimate, preferring the upper bound if a range is present.
   */
  protected function parseEstimate(string $estimate_text): float {
    $estimate_text = trim($estimate_text);
    if ($estimate_text === '') {
      return 0.0;
    }
    if (preg_match('/(\d+(?:\.\d+)?)(?:\s*-\s*(\d+(?:\.\d+)?))?/', $estimate_text, $m)) {
      return isset($m[2]) ? (float) $m[2] : (float) $m[1];
    }
    return 0.0;
  }

  /**
   * Create and schedule a Work Order, then double-save to trigger Automatic Label.
   */
  protected function createAndScheduleWorkOrder($etm, AccountInterface $current_user, int $property_id, int $contract_id, float $contract_estimate, DrupalDateTime $schedule_date, EntityInterface $section, string $description): void {
    $schedule_ts = $schedule_date->getTimestamp();
    $schedule_iso = $schedule_date->format('Y-m-d\TH:i:s');

    // Create Work Order.
    $work_order = $etm->getStorage('work_order')->create([
      'type' => 'christmas_decorations',
      'uid' => $current_user->id(),
      'created' => \Drupal::time()->getRequestTime(),
      'field_service' => 396,
      'field_property' => $property_id,
      'field_contract' => $contract_id,
      'field_status' => 1089,
      'field_invoiced' => 0,
      'field_estimated_price' => $contract_estimate,
      'field_work_todo_description' => [
        'value' => '<p>' . date('Y', $schedule_ts) . ' - ' . $description . '</p>',
        'format' => 'full_html',
      ],
    ]);
    $work_order->save(); // 1st save -> ID assigned.

    // Double-save to trigger Automatic Label tokens (ID now available).
    if ($reloaded = $etm->getStorage('work_order')->load($work_order->id())) {
      if (method_exists($reloaded, 'setNewRevision')) {
        $reloaded->setNewRevision(FALSE);
      }
      $reloaded->save(); // 2nd save -> Automatic Label updates title.
    }

    $work_order_id = $work_order->id();

    // Append to multi-value field_work_order on the section.
    $existing = $section->get('field_work_order')->getValue();
    $existing[] = ['target_id' => $work_order_id];
    $section->set('field_work_order', $existing);
    $section->save();

    // Create Scheduling entity.
    $scheduling = $etm->getStorage('scheduling')->create([
      'type' => 'work_order',
      'field_work_order' => ['target_id' => $work_order_id],
      'field_scheduled_firm' => FALSE,
      'field_scheduled' => TRUE,
      'field_scheduled_date_and_time' => [
        'value' => $schedule_iso,
      ],
      'field_scheduling_note' => 'Automatically Scheduled by System',
      'field_scheduled_order' => 10,
    ]);
    $scheduling->save();
  }

  /**
   * Access mirrors update access on the Contract.
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
