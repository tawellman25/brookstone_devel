<?php

namespace Drupal\wo_weed_spraying\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\flag\FlagServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Datetime\DrupalDateTime;

class WeedSprayWorkOrderController extends ControllerBase {

  protected $entityTypeManager;
  protected $currentUser;
  protected $flagService;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, AccountProxyInterface $currentUser, FlagServiceInterface $flagService) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
    $this->flagService = $flagService;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('flag')
    );
  }

  public function startWorkflow($property_id) {
    // Process $property_id to ensure it's an ID, not an object.
    if (is_object($property_id) && method_exists($property_id, 'id')) {
      $property_id = $property_id->id();
    }

    // Validate that $property_id is a scalar and not empty.
    if (!is_scalar($property_id) || empty($property_id)) {
      $this->messenger()->addError('Invalid property ID.');
      return $this->redirect('<front>');
    }

    // Load the property entity.
    $property = $this->entityTypeManager->getStorage('properties')->load($property_id);
    if (!$property) {
      $this->messenger()->addError('Property not found.');
      return $this->redirect('/teammates/work-orders/spraying/weeds/route');
    }

    // Get the current year and set the start/end timestamps for the year.
    $current_year = date('Y');
    $year_start = strtotime("{$current_year}-01-01 00:00:00");
    $year_end = strtotime("{$current_year}-12-31 23:59:59");

    // Check for existing weed spraying work orders for this property in the current year.
    $query = $this->entityTypeManager->getStorage('work_order')->getQuery()
      ->condition('type', 'weed_spraying')
      ->condition('field_property', $property_id)
      ->condition('created', [$year_start, $year_end], 'BETWEEN')
      ->sort('created', 'DESC') // Sort by creation date to get the latest first.
      ->accessCheck(TRUE); // Explicitly enable access checks.
    $existing_work_order_ids = $query->execute();

    if (!empty($existing_work_order_ids)) {
      // Load the existing work orders to check their status.
      $existing_work_orders = $this->entityTypeManager->getStorage('work_order')->loadMultiple($existing_work_order_ids);
      $allowed_statuses = [1097, 1098, 1283, 1281]; // Complete, Canceled, Warrantied, Invoiced.

      foreach ($existing_work_orders as $work_order) {
        $status = $work_order->get('field_status')->target_id;
        // If the status is not in the allowed list (or is NULL), redirect to this work order.
        if (!in_array($status, $allowed_statuses)) {
          $this->messenger()->addWarning(t('A weed spraying work order (ID: @id) with an active status already exists for this property in @year. Redirecting to it.', [
            '@id' => $work_order->id(),
            '@year' => $current_year,
          ]));
          $url = $work_order->toUrl('canonical'); // Redirect to the canonical page.
          return new RedirectResponse($url->toString());
        }
        // Since we sorted by created DESC, we can break after the first non-allowed status.
        break;
      }
    }

    $current_user_id = $this->currentUser()->id();

    // Create a new Weed Spraying Work Order.
    $work_order = $this->entityTypeManager->getStorage('work_order')->create([
      'type' => 'weed_spraying',
      'uid' => $current_user_id,
      'created' => time(),
      'field_invoiced' => 0,
      'field_property' => $property_id,
      'field_work_todo_description' => "$current_year - Weed Spray Route as needed",
    ]);
    $work_order->save();

    // Create the Scheduling entity and set the reference to the work_order.
    $scheduled_datetime = new DrupalDateTime('now', new \DateTimeZone('America/Denver'));
    $end_datetime = clone $scheduled_datetime;
    $end_datetime->modify('+15 minutes'); // Add 15 minutes
    $wo_schedule = $this->entityTypeManager->getStorage('scheduling')->create([
      'type' => 'work_order',
      'field_work_order' => $work_order->id(),
      'uid' => $current_user_id,
      'field_scheduled_date_and_time' => $scheduled_datetime->format('Y-m-d\TH:i:s'),
      'field_date' => [
        'value' => $scheduled_datetime->getTimestamp(),
        'end_value' => $end_datetime->getTimestamp(),
        'duration' => 15, // 15 minutes
      ],
      'field_assigned_to' => $current_user_id,
      'field_scheduled' => 1,
      'field_scheduling_note' => 'Spray Route Scheduled',
      'created' => time(),
    ]);
    $wo_schedule->save();

    // Redirect to the newly created Work Order.
    $url = $work_order->toUrl();
    return new RedirectResponse($url->toString());
  }

  protected function updateEntities($property, $contract) {
    // Add your logic here to update entities, change flags, etc.
  }
}