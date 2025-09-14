<?php

namespace Drupal\wo_actions\Plugin\Action;

use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;

/**
 * Updates Property Spraying Info from Invoiced Work Orders.
 *
 * @Action(
 *   id = "update_spraying_info_from_invoiced_work_order",
 *   label = "Update Property Spraying Info",
 *   type = "work_order",
 *   confirm = TRUE,
 *   type = "work_order"
 * )
 */
class UpdateSprayingInfoFromInvoicedWorkOrder extends ViewsBulkOperationsActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if ($entity && $entity->getEntityTypeId() === 'work_order') {
      $this->updateSprayingInfo($entity);
    }
  }

  /**
   * Updates the spraying info based on the given work order.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The work order entity.
   */
  protected function updateSprayingInfo(EntityInterface $entity) {
    // Ensure we're working with the latest data from the database
    $work_order = \Drupal::entityTypeManager()->getStorage('work_order')->load($entity->id());
    if (!$work_order) {
      $this->messenger()->addError("Work Order #" . $entity->id() . " not found.");
      return;
    }

    // Check if the work order is invoiced.
    if ($work_order->get('field_status')->target_id == 1281) {
      $propertyId = $work_order->get('field_property')->target_id;
      if ($propertyId) {
        // Load the property_spraying_info entity by property ID
        $query = \Drupal::entityQuery('property_spraying_info')
          ->accessCheck(FALSE)
          ->condition('field_property', $propertyId)
          ->condition('type', 'pre_emergent');
        $ids = $query->execute();
        
        if ($ids) {
          $propertySprayingInfo = \Drupal::entityTypeManager()->getStorage('property_spraying_info')->load(reset($ids));
          if ($propertySprayingInfo) {
            // Fetch wo_chemicals_used entities related to this work order.
            $chemicalsUsed = \Drupal::entityTypeManager()->getStorage('wo_chemicals_used')->loadByProperties([
              'field_work_order' => $work_order->id(),
            ]);

            // Aggregate information from wo_chemicals_used entities.
            $totalGallonsUsed = 0;
            $chemicalReferences = [];

            foreach ($chemicalsUsed as $chemical) {
                $gallons = $chemical->get('field_total_gallons_applied')->value;
                if ($gallons > $totalGallonsUsed) { // Update if a higher amount is found
                  $totalGallonsUsed = $gallons;
                }
                $chemicalReference = $chemical->get('field_chemical')->entity;
                if ($chemicalReference) {
                  $chemicalReferences[] = $chemicalReference;
                }
              }

            // Fetch signoff info
            $signoffInfo = \Drupal::entityTypeManager()->getStorage('wo_complete_info')->loadByProperties([
              'field_work_order' => $work_order->id(),
              'type' => 'spray_crew',
            ]);

            $signOffDateFormated = null;
            $signOffBy = null;

            if (!empty($signoffInfo)) {
              $signoffEntity = reset($signoffInfo);
              $signOffInfoTimestamp = $signoffEntity->get('field_date_completed')->value;
              $signOffDate = DrupalDateTime::createFromTimestamp($signOffInfoTimestamp);
              $signOffDate->setTimezone(new \DateTimeZone('UTC'));
              $signOffDateFormated = $signOffDate->format('Y-m-d\TH:i:s');
              $signOffBy = $signoffEntity->get('field_signed_off_by')->target_id;
            }

            // Update the spraying info
            $propertySprayingInfo->set('field_last_amount_applied', $totalGallonsUsed);
            $propertySprayingInfo->set('field_last_season_applied', $work_order->get('field_pre_emergent_season')->target_id == 1100 ? 'spring' : 'fall');
            $propertySprayingInfo->set('field_last_chemicals', $chemicalReferences);
            $propertySprayingInfo->set('field_last_applied_date', $signOffDateFormated);
            $propertySprayingInfo->set('field_last_applied_by', $signOffBy);

            $propertySprayingInfo->save();
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Handle configuration form submission if needed.
  }
}