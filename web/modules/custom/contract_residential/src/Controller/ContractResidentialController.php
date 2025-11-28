<?php

namespace Drupal\contract_residential\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\flag\FlagServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

class ContractResidentialController extends ControllerBase {

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
        // Handle invalid $property_id.
        $this->messenger()->addError('Invalid property ID.');
        return $this->redirect('<front>');
    }

    $current_user_id = $this->currentUser()->id();
    $current_year = date('Y');

    // Load the property entity.
    $property = $this->entityTypeManager->getStorage('properties')->load($property_id);
    if (!$property) {
      // Handle the case where the property entity is not found.
      $this->messenger()->addError('Property not found.');
      return $this->redirect('<front>');
    }

    // Check if the 'field_latest_contract' contains the current year.
    $latest_contract_year = $property->get('field_latest_contract')->value;
    if ($latest_contract_year && strpos($latest_contract_year, $current_year) !== false) {
      // If the 'field_latest_contract' contains the current year, redirect to the properties page.
      $this->messenger()->addMessage('A contract for the current year already exists.');

      // Redirect to the 'admin/office/properties' path.
      $url = Url::fromUri('internal:/admin/office/properties');
      return new RedirectResponse($url->toString());
    }

    // Create a new Residential Contract.
    $contract = $this->entityTypeManager->getStorage('contracts')->create([
      'type' => 'residential',
      'uid' => $current_user_id,
      'created' => time(),
      'field_property' => $property_id,
      'field_contract_status' => 1117,
    ]);
    $contract->save();

    // Update the Property with the Current Contract year.
    $property->set('field_latest_contract', $current_year);
    $property->save();

    // Update existing entities, change flags, etc., as needed.
    $this->updateEntities($property, $contract);

    // Redirect to the edit form of the newly created contract.
    $url = $contract->toUrl('edit-form');
    return new RedirectResponse($url->toString());
  }

  protected function updateEntities($property, $contract) {
    // Add your logic here to update entities, change flags, etc.
    // This could involve setting additional fields on the work order or tasks list,
    // updating related entities, or performing other necessary operations.
  }
  // Additional methods as needed.
}
