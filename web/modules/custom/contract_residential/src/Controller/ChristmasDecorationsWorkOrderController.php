<?php

namespace Drupal\contract_residential\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for creating "Christmas Decorations" work orders.
 */
class ChristmasDecorationsWorkOrderController extends ControllerBase {

  /**
   * Creates a "Christmas Decorations" work order for a given contract.
   *
   * @param int $contract_id
   *   The ID of the contract.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the front page.
   */
  public function createChristmasDecorationsWorkOrder($contract_id) {
    // Retrieve necessary services.
    $entity_type_manager = \Drupal::service('entity_type.manager');
    $messenger = \Drupal::messenger();
    $current_user = \Drupal::currentUser();

    // Load the Contract entity.
    $contract = $entity_type_manager->getStorage('contracts')->load($contract_id);
    if (!$contract) {
      $messenger->addError('Contract not found.');
      return new RedirectResponse(\Drupal::url('<front>'));
    }

    // Load the referenced Dethatching entity.
    $christmas_decorations_id = $contract->get('field_deer_protection_wire_for_t')->target_id;
    $christmas_decorations_section = $entity_type_manager->getStorage('contract_sections')->load($christmas_decorations_id);
    if (!$christmas_decorations_section) {
      $messenger->addError('Referenced Dethatching Section entity not found.');
      return;
    }

    // Check if a work order already exists.
    if (!$christmas_decorations_section->get('field_2nd_work_order')->isEmpty()) {
      $messenger->addError("A PUT UP  Dethatching Work Order already exists for this Contract #$contract_id.");
      return;
    }

    // Get the value of the 'field_estimate' field from the Dethatching entity & Extract the last number.
    $estimate_text = $christmas_decorations_section->get('field_estimate')->value;
    // Check if the estimate text is not null and matches the pattern.
    if ($estimate_text !== null) {
      // Check if the estimate text contains a hyphen.
      if (strpos($estimate_text, '-') !== false) {
        // If the estimate text contains a hyphen, use the second number as the estimated price.
        preg_match('/(\d+)\s*-\s*(\d+)/', $estimate_text, $matches);
        if (!empty($matches)) {
          $contract_estimate = (float) $matches[2];
        } else {
          // If the pattern match fails, set the estimated price to 0.0.
          $contract_estimate = 0.0;
        }
      } else {
        // If the estimate text does not contain a hyphen, use the entire text as the estimated price.
        $contract_estimate = (float) $estimate_text;
      }
    } else {
      // If the estimate text is null, set the estimated price to 0.0.
      $contract_estimate = 0.0;
    }

    // Get the value of the 'field_christmas_decorations_season' field from the Pre-emergent entity.
    $contract_season = $christmas_decorations_section->get('field_christmas_decorations_season')->target_id;
    $season_term = $entity_type_manager->getStorage('taxonomy_term')->load($contract_season);
    if ($season_term) {
      // Retrieve the name of the taxonomy term.
      $season_name = $season_term->getName();
    }

    // Load the referenced Property entity.
    $property_id = $contract->get('field_property')->target_id;
    $property = $entity_type_manager->getStorage('properties')->load($property_id);
    if (!$property) {
      $messenger->addError('Referenced Property not found.');
      return new RedirectResponse(\Drupal::url('<front>'));
    }

    // Get the landscape detail from the referrenced Property
    $query = \Drupal::entityTypeManager()->getStorage('property_landscape_details')->getQuery()
    ->condition('field_property', $property_id)
    ->accessCheck(FALSE); // It's important to explicitly set access checks as per your requirements.

    $result = $query->execute();

    if ($result) {
      $landscape_details_ids = array_keys($result);
      $landscape_details_id = reset($landscape_details_ids); // Assuming you're interested in the first result.
      $property_landscape_details = \Drupal::entityTypeManager()->getStorage('property_landscape_details')->load($landscape_details_id);

      // Now, $property_landscape_details is the entity you're looking for.
      // You can access 'field_turf_sq_footage' or any other fields you need.
      if ($property_landscape_details && !$property_landscape_details->get('field_turf_sq_footage')->isEmpty()) {
        $turf_sq_footage = $property_landscape_details->get('field_turf_sq_footage')->value;
        // Use $turf_sq_footage as needed.
      }

      if ($property_landscape_details && !$property_landscape_details->get('field_turf_sq_footage')->isEmpty()) {
        // Assuming you meant 'field_turf_sq_footage' here, not 'field_current_turf_sq_footage'
        $current_turf_sq_footage = $property_landscape_details->get('field_turf_sq_footage')->value;
      } else {
        $current_turf_sq_footage = '0'; // Default value if the field is empty or doesn't exist
      }
    } else {
    // Handle the case where no matching property_landscape_details entity is found
    $current_turf_sq_footage = '0'; // Default value
    } 
    
    // Create a new Christmas Decorations Work Order.
    $work_order = $entity_type_manager->getStorage('work_order')->create([
      'type' => 'christmas_decorations',
      'uid' => $current_user->id(),
      'created' => time(),
      'field_service' => 396,
      'field_property' => $property_id,
      'field_contract' => $contract_id,
      'field_status' => 1089,
      'field_work_todo_description' => [
        'value' => "<p>" . date('Y') . " - <strong>Take Down</strong> Christmas Decorations lawn as described</p>",
        'format' => 'full_html',
      ],
    ]);
    $work_order->save();

    // Get the ID of the created work_order.
    $work_order_id = $work_order->id();

    // Set the value of the Contract Section's 'field_work_order' reference field to the ID of the created work_order.
    $christmas_decorations_section->set('field_work_order', $work_order_id);
    $christmas_decorations_section->save();

    // Display success message.
    $messenger->addMessage($this->t("Christmas Decorations <strong>Take Down</strong> work order created for Contract #$contract_id."));

    // Implement the logic to create the christmas_decorations work order.
    // Note: You need to adapt the logic from your Action to fit into this controller method.

    // Redirect the user to the Property entity page.
    return new RedirectResponse($contract->toUrl()->toString());
  }

}
