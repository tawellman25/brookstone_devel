<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Creates a Spring Pinyon Pine Ips Beetle work order.
 *
 * @Action(
 *   id = "create_pinyon_pine_ips_beetle_spring_work_order_action",
 *   label = @Translation("Create SPRING Pinyon Pine Ips Beetle Work Order"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE,
 *   type = "contracts"
 * )
 */
class CreatePinyonPineIpsBeetleSpringWorkOrderAction extends ViewsBulkOperationsActionBase {
  use MessengerTrait;

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if ($entity && $entity->getEntityTypeId() === 'contracts') {
      // Retrieve necessary services.
      $entity_type_manager = \Drupal::service('entity_type.manager');
      $messenger = \Drupal::messenger();
      $current_user = \Drupal::currentUser();

      // Extract contract ID.
      $contract_id = $entity->id();

      // Load the Contract entity.
      $contract = $entity_type_manager->getStorage('contracts')->load($contract_id);
      if (!$contract) {
        $messenger->addError($this->t('Contract not found for ID @id.', ['@id' => $contract_id]));
        return;
      }

      // Load the referenced Pinyon Pine Ips Beetle entity.
      $pinyon_pine_ips_beetle_spray_id = $contract->get('field_ips_beetle_on_pinion_pine')->target_id;
      $pinyon_pine_ips_beetle_spray_section = $entity_type_manager->getStorage('contract_sections')->load($pinyon_pine_ips_beetle_spray_id);
      if (!$pinyon_pine_ips_beetle_spray_section) {
        $messenger->addError($this->t('Referenced Pinyon Pine Ips Beetle entity not found for Contract #@id.', ['@id' => $contract_id]));
        return;
      }

      // Check if a work order already exists for Spring.
      if (!$pinyon_pine_ips_beetle_spray_section->get('field_work_order')->isEmpty()) {
        $messenger->addError($this->t('A Spring Pinyon Pine Ips Beetle Work Order already exists for Contract #@id.', ['@id' => $contract_id]));
        return;
      }

      // Load the Property referenced in the Contract.
      $property_id = $contract->get('field_property')->target_id;
      $property = $entity_type_manager->getStorage('properties')->load($property_id);
      if (!$property) {
        $messenger->addError($this->t('Referenced Property not found for Contract #@id.', ['@id' => $contract_id]));
        return;
      }

      // Check for existing work order for this property and Spring season.
      $existing_work_orders = $entity_type_manager->getStorage('work_order')->getQuery()
        ->condition('type', 'pinion_pine_ips_beetle')
        ->condition('field_property', $property_id)
        ->condition('field_ips_beetle_season_ref', 1100) // Spring
        ->accessCheck(FALSE)
        ->count()
        ->execute();

      if ($existing_work_orders > 0) {
        $messenger->addError($this->t('A Spring Pinyon Pine Ips Beetle Work Order already exists for this property in Contract #@id.', ['@id' => $contract_id]));
        return;
      }

      // Get the value of the 'field_estimate' field and extract the estimated price.
      $estimate_text = $pinyon_pine_ips_beetle_spray_section->get('field_estimate')->value;
      if ($estimate_text !== null) {
        if (strpos($estimate_text, '-') !== false) {
          preg_match('/(\d+)\s*-\s*(\d+)/', $estimate_text, $matches);
          $contract_estimate = !empty($matches) ? (float) $matches[2] : 0.0;
        } else {
          $contract_estimate = (float) $estimate_text;
        }
      } else {
        $contract_estimate = 0.0;
      }

      // Load property landscape details and get Pinyon Pine tree count.
      $pinyon_pine_tree_count = 0;
      $query = $entity_type_manager->getStorage('property_landscape_details')->getQuery()
        ->condition('field_property', $property_id)
        ->accessCheck(FALSE);
      $result = $query->execute();

      if ($result) {
        $landscape_details_id = reset(array_keys($result));
        $property_landscape_details = $entity_type_manager->getStorage('property_landscape_details')->load($landscape_details_id);
        if ($property_landscape_details && !$property_landscape_details->get('field_number_of_trees_pinyon')->isEmpty()) {
          $pinyon_pine_tree_count = $property_landscape_details->get('field_number_of_trees_pinyon')->value;
        }
      }

      // Create a new Pinyon Pine Ips Beetle Work Order for Spring.
      $work_order = $entity_type_manager->getStorage('work_order')->create([
        'type' => 'pinion_pine_ips_beetle',
        'uid' => $current_user->id(),
        'created' => time(),
        'field_service' => 400,
        'field_property' => $property_id,
        'field_contract' => $contract_id,
        'field_status' => 1089,
        'field_invoiced' => 0,
        'field_estimated_price' => $contract_estimate,
        'field_ips_beetle_season_ref' => 1100, // Spring
        'field_pinyon_pine_tree_count' => $pinyon_pine_tree_count, // Set initial tree count
        'field_work_todo_description' => [
          'value' => $pinyon_pine_tree_count > 0
            ? strtr("<p>@year - <strong>Spring</strong> Spray Pinyon Pine Ips Beetle as described (for @count trees)</p>", [
                '@year' => date('Y'),
                '@count' => $pinyon_pine_tree_count,
              ])
            : strtr("<p>@year - <strong>Spring</strong> Spray Pinyon Pine Ips Beetle as described (Please Update Tree Count)</p>", [
                '@year' => date('Y'),
              ]),
          'format' => 'full_html',
        ],
      ]);
      $work_order->save();

      // Get the ID of the created work order.
      $work_order_id = $work_order->id();

      // Set the Spring work order reference in the contract section.
      $pinyon_pine_ips_beetle_spray_section->set('field_work_order', $work_order_id);
      $pinyon_pine_ips_beetle_spray_section->save();

      // Display success message.
      $messenger->addMessage($this->t('<strong>Spring</strong> Pinyon Pine Ips Beetle work order created for Contract #@id with @count trees.', [
        '@id' => $contract_id,
        '@count' => $pinyon_pine_tree_count,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }
}