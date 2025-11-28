<?php

namespace Drupal\wo_lawn_mowing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\flag\FlagServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Datetime\DrupalDateTime;

class WOLawnMowingTaskController extends ControllerBase {

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
    // Process $property_id to ensure it's an ID, not an object
    if (is_object($property_id) && method_exists($property_id, 'id')) {
        $property_id = $property_id->id();
    }

    // Validate that $property_id is a scalar and not empty
    if (!is_scalar($property_id) || empty($property_id)) {
        // Handle invalid $property_id. For example, log an error or redirect.
        drupal_set_message('Invalid property ID.', 'error');
        return $this->redirect('<front>');
    }

    $current_user_id = $this->currentUser()->id();

    $connection = \Drupal::database();

    // Start with the base table for 'wo_tasks_list'.
    $query = $connection->select('wo_tasks_list_field_data', 'wtlfd');
    // Join to access the 'field_work_order' reference field data.
    $query->leftJoin('wo_tasks_list__field_work_order', 'wtlfwo', 'wtlfwo.entity_id = wtlfd.id');
    // Join to access the 'field_completed' to check if the task is incomplete.
    $query->leftJoin('wo_tasks_list__field_completed', 'wtlfc', 'wtlfc.entity_id = wtlfd.id');

    $query->fields('wtlfd', ['id'])
        ->condition('wtlfd.uid', $current_user_id)
        ->condition('wtlfd.type', 'lawn_mowing')
        // Adjust this condition to account for 'field_completed' being NULL or 0.
        ->where('(wtlfc.field_completed_value IS NULL OR wtlfc.field_completed_value = :zero)', [':zero' => 0])
        ->orderBy('wtlfd.created', 'DESC')
        ->range(0, 1);
    $result = $query->execute()->fetchField();
    
    if ($result) {
        // Found the latest incomplete wo_tasks_list, redirect to its edit page.
        $url = $this->entityTypeManager()->getStorage('wo_tasks_list')->load($result)->toUrl('edit-form');
        return new RedirectResponse($url->toString());
    } else {

        // Get current year and week number
        $year = date('Y');
        $week = date('W');  

        // Create a new Lawn Mowing Work Order if no incomplete tasks are found.
        $work_order = $this->entityTypeManager->getStorage('work_order')->create([
        'type' => 'lawn_mowing',
        'uid' => $current_user_id,
        'created' => time(),
        'field_work_todo_description'  => "$year - Week $week - Lawn Mowing",
        'field_property' => $property_id,
        'field_invoiced' => 0,
        ]);
        $work_order->save();

        // Flag the work_order as "Clocked In" using the 'work_order_timer' flag.
        $flag = $this->flagService->getFlagById('work_order_timer');
        if ($flag) {
        $this->flagService->flag($flag, $work_order, $this->currentUser);
        }

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

        // Create the wo_task_list entity and set the reference to the work_order.
        $wo_tasks_list = $this->entityTypeManager->getStorage('wo_tasks_list')->create([
        'type' => 'lawn_mowing',
        'field_work_order' => $work_order->id(),
        'uid' => $current_user_id,
        'created' => time(),
        ]);
        $wo_tasks_list->save();

        // Update existing entities, change flags, etc., as needed.
        $this->updateEntities($work_order, $wo_tasks_list);

        // Redirect to the edit form of the newly created wo_task_list.
        $url = $wo_tasks_list->toUrl('edit-form');
        $destination = \Drupal\Core\Url::fromRoute('entity.work_order.canonical', ['work_order' => $work_order->id()])->toString();
        $url->setOption('query', ['destination' => $destination]);
        return new RedirectResponse($url->toString());
    }
  }

  public function customAccessCheck(AccountInterface $account, Request $request) {
    // Debug statement to check the current user ID.
    \Drupal::logger('wo_lawn_mowing')->debug('Current user ID: ' . $account->id());

    // Check if the user has the necessary permission.
    if ($account->hasPermission('create work_order entities')) {
        \Drupal::logger('wo_lawn_mowing')->debug('User has permission: Create new Work Order entities');
        return AccessResult::allowed();
    } else {
        \Drupal::logger('wo_lawn_mowing')->debug('User does not have permission: Create new Work Order entities');
    }

    // If access is denied, log a message.
    \Drupal::logger('wo_lawn_mowing')->debug('Access denied for user: ' . $account->getAccountName());
    return AccessResult::forbidden();
  }

  protected function updateEntities($work_order, $wo_tasks_list) {
    // Add your logic here to update entities, change flags, etc.
    // This could involve setting additional fields on the work order or tasks list,
    // updating related entities, or performing other necessary operations.
  }
  // Additional methods as needed.
}
