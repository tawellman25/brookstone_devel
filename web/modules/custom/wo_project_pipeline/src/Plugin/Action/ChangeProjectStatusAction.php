<?php

namespace Drupal\wo_project_pipeline\Plugin\Action;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;

/**
 * Changes work order status via a dropdown selection.
 *
 * @Action(
 *   id = "wo_pipeline_change_status",
 *   label = @Translation("Change Status"),
 *   type = "work_order",
 *   confirm = TRUE
 * )
 */
class ChangeProjectStatusAction extends ViewsBulkOperationsActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if (!$entity || $entity->getEntityTypeId() !== 'work_order') {
      return;
    }

    $status_tid = $this->configuration['status_tid'] ?? NULL;
    if (empty($status_tid)) {
      return;
    }

    $entity->set('field_status', $status_tid);
    $entity->save();

    $wo_id = $entity->get('field_work_order_id')->value ?: $entity->id();
    $status_term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($status_tid);
    $status_label = $status_term ? $status_term->label() : $status_tid;
    \Drupal::messenger()->addMessage("WO #$wo_id: Status changed to $status_label.");
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Only offer pipeline-relevant statuses, in a deliberate order.
    $allowed_tids = [1503, 1091, 1090, 1092, 1097, 1098];
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadMultiple($allowed_tids);
    $options = [];
    foreach ($allowed_tids as $tid) {
      if (isset($terms[$tid])) {
        $options[$tid] = $terms[$tid]->label();
      }
    }

    $form['status_tid'] = [
      '#type' => 'select',
      '#title' => $this->t('New Status'),
      '#options' => $options,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['status_tid'] = $form_state->getValue('status_tid');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
