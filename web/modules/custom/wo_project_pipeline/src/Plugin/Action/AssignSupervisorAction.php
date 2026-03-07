<?php

namespace Drupal\wo_project_pipeline\Plugin\Action;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;

/**
 * Assigns a supervisor to work orders.
 *
 * @Action(
 *   id = "wo_pipeline_assign_supervisor",
 *   label = @Translation("Assign Supervisor"),
 *   type = "work_order",
 *   confirm = TRUE
 * )
 */
class AssignSupervisorAction extends ViewsBulkOperationsActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if (!$entity || $entity->getEntityTypeId() !== 'work_order') {
      return;
    }

    $supervisor_uid = $this->configuration['supervisor_uid'] ?? NULL;
    if (empty($supervisor_uid)) {
      return;
    }

    $entity->set('field_supervisor', $supervisor_uid);
    $entity->save();

    $wo_id = $entity->get('field_work_order_id')->value ?: $entity->id();
    $user = \Drupal::entityTypeManager()->getStorage('user')->load($supervisor_uid);
    $name = $user ? $user->getDisplayName() : $supervisor_uid;
    \Drupal::messenger()->addMessage("WO #$wo_id: Supervisor assigned to $name.");
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Load users with supervisor role or higher.
    $query = \Drupal::entityQuery('user')
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', ['supervisor', 'administration', 'site_assistant', 'site_admin', 'administrator'], 'IN')
      ->sort('name');
    $uids = $query->execute();
    $users = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple($uids);

    $options = [];
    foreach ($users as $user) {
      $options[$user->id()] = $user->getDisplayName();
    }

    $form['supervisor_uid'] = [
      '#type' => 'select',
      '#title' => $this->t('Supervisor'),
      '#options' => $options,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['supervisor_uid'] = $form_state->getValue('supervisor_uid');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
