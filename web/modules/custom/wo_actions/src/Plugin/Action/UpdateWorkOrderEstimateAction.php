<?php

namespace Drupal\wo_actions\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Marks Work Order as Invoiced.
 *
 * @Action(
 *   id = "update_work_order_estimate_action",
 *   label = @Translation("Update Work Order Estimate"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class UpdateWorkOrderEstimateAction extends ViewsBulkOperationsActionBase {
  use MessengerTrait;

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if ($entity && $entity->getEntityTypeId() === 'work_order') {
      // Retrieve necessary services.
      $entity_type_manager = \Drupal::service('entity_type.manager');
      $messenger = \Drupal::messenger();
      $current_user = \Drupal::currentUser();

      $bundle = $entity->bundle();
      $workOrderEstimate = $entity->get('field_estimated_price')->value;
      $contract_id = $entity->get('field_contract')->target_id;

      switch ($bundle) {
        // Aerating Work Order Type
        case 'aerating':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $aerating_id = $contractEntity->get('field_aerating_of_lawn')->target_id;
          $aerating_section = $entity_type_manager->getStorage('contract_sections')->load($aerating_id);
          if (!$aerating_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $aerating_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Cooley Spruce Gall Work Order Type
        case 'cooley_spruce_gall':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $cooley_spruce_gall_id = $contractEntity->get('field_cooley_spruce_gall_treatme')->target_id;
          $cooley_spruce_gall_section = $entity_type_manager->getStorage('contract_sections')->load($cooley_spruce_gall_id);
          if (!$cooley_spruce_gall_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $cooley_spruce_gall_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Deciduous Bore Work Order Type
        case 'deciduous_bore':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $deciduous_bore_id = $contractEntity->get('field_deciduous_bore_treatment')->target_id;
          $deciduous_bore_section = $entity_type_manager->getStorage('contract_sections')->load($deciduous_bore_id);
          if (!$deciduous_bore_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $deciduous_bore_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Deer Prevention Work Order Type
        case 'deer_prevention':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $deer_prevention_id = $contractEntity->get('field_deer_protection_wire_for_t')->target_id;
          $deer_prevention_section = $entity_type_manager->getStorage('contract_sections')->load($deer_prevention_id);
          if (!$deer_prevention_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $deer_prevention_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Dethatching Work Order Type
        case 'dethatching':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $dethatching_id = $contractEntity->get('field_dethatching_of_lawn_areas')->target_id;
          $dethatching_section = $entity_type_manager->getStorage('contract_sections')->load($dethatching_id);
          if (!$dethatching_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $dethatching_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Dormant Oil Work Order Type
        case 'dormant_oil':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $dormant_oil_id = $contractEntity->get('field_dormant_oil_spray')->target_id;
          $dormant_oil_section = $entity_type_manager->getStorage('contract_sections')->load($dormant_oil_id);
          if (!$dormant_oil_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $dormant_oil_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Fall Cleanup Work Order Type
        case 'fall_cleanup':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $fall_cleanup_id = $contractEntity->get('field_fall_cleanup')->target_id;
          $fall_cleanup_section = $entity_type_manager->getStorage('contract_sections')->load($fall_cleanup_id);
          if (!$fall_cleanup_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $fall_cleanup_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Fertilizing Work Order Type
        case 'fertilizing':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $fertilizing_id = $contractEntity->get('field_lawn_fertilizing_broadleaf')->target_id;
          $fertilizing_section = $entity_type_manager->getStorage('contract_sections')->load($fertilizing_id);
          if (!$fertilizing_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $fertilizing_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Fertilizing Trees and Shrubs Work Order Type
        case 'fertilizing_trees_and_shrubs':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $fertilizing_trees_and_shrubs_id = $contractEntity->get('field_fertilizing_trees_shrubs')->target_id;
          $fertilizing_trees_and_shrubs_section = $entity_type_manager->getStorage('contract_sections')->load($fertilizing_trees_and_shrubs_id);
          if (!$fertilizing_trees_and_shrubs_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $fertilizing_trees_and_shrubs_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Grub Prevention Work Order Type
        case 'grub_prevention':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $grub_prevention_id = $contractEntity->get('field_grub_prevention_on_lawn')->target_id;
          $grub_prevention_section = $entity_type_manager->getStorage('contract_sections')->load($grub_prevention_id);
          if (!$grub_prevention_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $grub_prevention_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Pinion Pine Ips Beetle Work Order Type
        case 'pinion_pine_ips_beetle':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $pinion_pine_ips_beetle_id = $contractEntity->get('field_ips_beetle_on_pinion_pine')->target_id;
          $pinion_pine_ips_beetle_section = $entity_type_manager->getStorage('contract_sections')->load($pinion_pine_ips_beetle_id);
          if (!$pinion_pine_ips_beetle_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $pinion_pine_ips_beetle_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Pre-emergent Work Order Type
        case 'pre_emergent':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $pre_emergent_id = $contractEntity->get('field_pre_emergent')->target_id;
          $pre_emergent_section = $entity_type_manager->getStorage('contract_sections')->load($pre_emergent_id);
          if (!$pre_emergent_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $pre_emergent_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Spring Cleanup Work Order Type
        case 'spring_cleanup':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $spring_cleanup_id = $contractEntity->get('field_spring_cleanup')->target_id;
          $spring_cleanup_section = $entity_type_manager->getStorage('contract_sections')->load($spring_cleanup_id);
          if (!$spring_cleanup_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $spring_cleanup_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Sprinkler Start-Up Work Order Type
        case 'sprinkler_start_up':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $sprinkler_start_up_id = $contractEntity->get('field_irrigation_start_up')->target_id;
          $sprinkler_start_up_section = $entity_type_manager->getStorage('contract_sections')->load($sprinkler_start_up_id);
          if (!$sprinkler_start_up_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $sprinkler_start_up_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Sprinkler Winterizing Work Order Type
        case 'sprinkler_winterizing':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $sprinkler_winterizing_id = $contractEntity->get('field_irrigation_shut_down')->target_id;
          $sprinkler_winterizing_section = $entity_type_manager->getStorage('contract_sections')->load($sprinkler_winterizing_id);
          if (!$sprinkler_winterizing_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $sprinkler_winterizing_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Summer Pruning Work Order Type
        case 'summer_pruning':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $summer_pruning_id = $contractEntity->get('field_summer_hedge_shrub_pruning')->target_id;
          $summer_pruning_section = $entity_type_manager->getStorage('contract_sections')->load($summer_pruning_id);
          if (!$summer_pruning_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $summer_pruning_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Trunk Bore Work Order Type
        case 'trunk_bore':
          // Load the Contract entity.
          $contractEntity = $entity_type_manager->getStorage('contracts')->load($contract_id);
          if (!$contractEntity) {
            $messenger->addError("Contract #$contract_id not found.");
            return;
          }
          
          // Load the referenced Aerating entity.
          $trunk_bore_id = $contractEntity->get('field_trunk_bore_prevention')->target_id;
          $trunk_bore_section = $entity_type_manager->getStorage('contract_sections')->load($trunk_bore_id);
          if (!$trunk_bore_section) {
            $messenger->addError('Referenced Aerating entity not found.');
            return;
          }

          // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
          $estimate_text = $trunk_bore_section->get('field_estimate')->value;
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

          $entity->set('field_estimated_price', $contract_estimate);
          $entity->save();
          
          break; 

        // Add cases for other bundle types as necessary.
        default:
          $messenger->addError("Unsupported bundle type: $bundle.");
          return;

      // Display success message.
      $messenger->addMessage($this->t("The Estimate Price for Work Order #$workorder_id has been updated."));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}

