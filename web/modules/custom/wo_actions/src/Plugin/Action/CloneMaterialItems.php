<?php

namespace Drupal\wo_actions\Plugin\Action;

use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;

/**
 * Clone Material Items with Manual Input.
 *
 * @Action(
 *   id = "clone_material_items_action",
 *   label = @Translation("Clone Material Items"),
 *   confirm = TRUE,
 *   category = @Translation("Custom"),
 *   type = "work_order_materials"
 * )
 */
class CloneMaterialItems extends ViewsBulkOperationsActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    // Since getContext might not be available, we'll check for the context differently
    $context = $this->context;
    
    // Check if this is the start of the operation or if we've already set the IDs
    if (!\Drupal::service('session')->has('entity_ids_to_clone')) {
      $entity_ids = array_keys($context['list']);
      
      if (!empty($entity_ids)) {
        \Drupal::service('session')->set('entity_ids_to_clone', $entity_ids);
        \Drupal::logger('wo_actions')->notice('Preparing to clone action for ' . count($entity_ids) . ' entities');

        $form = \Drupal::formBuilder()->getForm('Drupal\wo_actions\Form\CloneMaterialItemsForm');
        if (!$form) {
          \Drupal::logger('wo_actions')->error('Form not built for clone action');
        } else {
          \Drupal::logger('wo_actions')->notice('Form built successfully');
        }
        $form_render_array = \Drupal::service('renderer')->renderRoot($form);
        $form_html = (string) $form_render_array;
        $response = new AjaxResponse();
        $response->addCommand(new OpenModalDialogCommand('Clone Material Items', $form_html, ['width' => '800']));
        return $response;
      }
    } else {
      // This isn't the first run; we've already set up for cloning, so do nothing here
      \Drupal::logger('wo_actions')->notice('Skipping form display for this batch');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}