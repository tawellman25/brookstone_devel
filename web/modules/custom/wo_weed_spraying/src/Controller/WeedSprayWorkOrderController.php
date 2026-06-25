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

    // Find the genuinely-active open weed spraying WO for this property; heal any
    // abandoned ones (stale-empty -> Canceled, resurrected -> Complete) so they
    // no longer trap the tech here. Only a truly-active WO triggers a redirect.
    $current_year = date('Y');
    $active_wo = _wo_weed_spraying_find_active_open_wo((int) $property_id, TRUE);
    if ($active_wo) {
      $this->messenger()->addWarning(t('A weed spraying work order (ID: @id) is already active for this property in @year. Redirecting to it.', [
        '@id' => $active_wo->id(),
        '@year' => $current_year,
      ]));
      return new RedirectResponse($active_wo->toUrl('canonical')->toString());
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
    try {
      $work_order->save();
    }
    catch (\Drupal\Core\Entity\EntityStorageException $e) {
      // Presave guard still blocked on a genuinely-active duplicate — go to it.
      $active = _wo_weed_spraying_find_active_open_wo((int) $property_id, TRUE);
      if ($active) {
        return new RedirectResponse($active->toUrl('canonical')->toString());
      }
      throw $e;
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

    // Redirect to the newly created Work Order.
    $url = $work_order->toUrl();
    return new RedirectResponse($url->toString());
  }

  protected function updateEntities($property, $contract) {
    // Add your logic here to update entities, change flags, etc.
  }
}