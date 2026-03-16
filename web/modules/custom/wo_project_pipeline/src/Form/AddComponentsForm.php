<?php

declare(strict_types=1);

namespace Drupal\wo_project_pipeline\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Views;

/**
 * Modal form to add landscape component estimates to a container estimate.
 */
class AddComponentsForm extends FormBase {

  /**
   * The container estimate ID.
   */
  protected int $estimateId = 0;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'add_components_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $estimate_id = 0): array {
    $this->estimateId = $estimate_id;
    $form_state->set('estimate_id', $estimate_id);

    // Load and validate the container estimate.
    $container = \Drupal::entityTypeManager()
      ->getStorage('estimate')
      ->load($estimate_id);

    if (!$container || $container->bundle() !== 'landscaping' || empty($container->get('field_is_container')->value)) {
      $form['error'] = [
        '#markup' => '<p>Invalid container estimate.</p>',
      ];
      return $form;
    }

    // Load landscape component terms via the references view.
    $options = [];
    $view = Views::getView('landscaping_component_references');
    if ($view) {
      $view->setDisplay('entity_reference_1');
      $view->execute();
      foreach ($view->result as $row) {
        $term = $row->_entity;
        $label = $term->label();
        if ($term->hasField('field_service_name') && !$term->get('field_service_name')->isEmpty()) {
          $label = $term->get('field_service_name')->value;
        }
        $options[$term->id()] = $label;
      }
    }

    if (empty($options)) {
      $form['error'] = [
        '#markup' => '<p>No landscape component types are configured.</p>',
      ];
      return $form;
    }

    // Find which component types already exist as children.
    $existing_ids = \Drupal::entityTypeManager()
      ->getStorage('estimate')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_parent_estimate', $estimate_id)
      ->condition('type', 'landscaping')
      ->execute();

    $existing_tids = [];
    if (!empty($existing_ids)) {
      $existing_estimates = \Drupal::entityTypeManager()
        ->getStorage('estimate')
        ->loadMultiple($existing_ids);
      foreach ($existing_estimates as $est) {
        if (!$est->get('field_estimate_type')->isEmpty()) {
          $existing_tids[] = (int) $est->get('field_estimate_type')->target_id;
        }
      }
    }

    // Build default values — already-existing ones are checked.
    $default_values = [];
    foreach ($existing_tids as $tid) {
      if (isset($options[$tid])) {
        $default_values[$tid] = $tid;
      }
    }

    $form['#prefix'] = '<div id="add-components-form-wrapper">';
    $form['#suffix'] = '</div>';

    $form['components'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Select components to add'),
      '#options' => $options,
      '#default_value' => $default_values,
    ];

    // Disable already-existing checkboxes via #after_build.
    if (!empty($existing_tids)) {
      $form['components']['#existing_tids'] = $existing_tids;
      $form['components']['#after_build'][] = [static::class, 'disableExisting'];
    }

    $form['estimate_id'] = [
      '#type' => 'hidden',
      '#value' => $estimate_id,
    ];

    $form['actions'] = ['#type' => 'actions'];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Components'),
      '#ajax' => [
        'callback' => '::submitAjax',
        'wrapper' => 'add-components-form-wrapper',
      ],
    ];

    $form['actions']['cancel'] = [
      '#type' => 'button',
      '#value' => $this->t('Cancel'),
      '#ajax' => [
        'callback' => '::cancelAjax',
      ],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * After-build callback to disable already-existing component checkboxes.
   */
  public static function disableExisting(array $element, FormStateInterface $form_state): array {
    $existing_tids = $element['#existing_tids'] ?? [];
    foreach ($existing_tids as $tid) {
      if (isset($element[$tid])) {
        $element[$tid]['#attributes']['disabled'] = 'disabled';
      }
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Handled by submitAjax in modal context.
  }

  /**
   * AJAX submit handler — creates component estimates and closes modal.
   */
  public function submitAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $estimate_id = (int) $form_state->getValue('estimate_id');
    $selected = array_filter($form_state->getValue('components'));

    $response = new AjaxResponse();

    if (empty($selected)) {
      $response->addCommand(new CloseModalDialogCommand());
      return $response;
    }

    // Load container estimate.
    $container = \Drupal::entityTypeManager()
      ->getStorage('estimate')
      ->load($estimate_id);

    if (!$container) {
      $response->addCommand(new CloseModalDialogCommand());
      return $response;
    }

    // Find existing component TIDs to skip duplicates.
    $existing_ids = \Drupal::entityTypeManager()
      ->getStorage('estimate')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_parent_estimate', $estimate_id)
      ->condition('type', 'landscaping')
      ->execute();

    $existing_tids = [];
    if (!empty($existing_ids)) {
      $existing_estimates = \Drupal::entityTypeManager()
        ->getStorage('estimate')
        ->loadMultiple($existing_ids);
      foreach ($existing_estimates as $est) {
        if (!$est->get('field_estimate_type')->isEmpty()) {
          $existing_tids[] = (int) $est->get('field_estimate_type')->target_id;
        }
      }
    }

    $current_uid = \Drupal::currentUser()->id();
    $estimate_storage = \Drupal::entityTypeManager()->getStorage('estimate');
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

    foreach ($selected as $tid => $value) {
      $tid = (int) $tid;
      if (!$tid || in_array($tid, $existing_tids, TRUE)) {
        continue;
      }

      $term = $term_storage->load($tid);
      if (!$term) {
        continue;
      }

      $component_name = $term->label();
      if ($term->hasField('field_service_name') && !$term->get('field_service_name')->isEmpty()) {
        $component_name = $term->get('field_service_name')->value;
      }

      // Get estimate bundle from service term.
      $bundle = 'landscaping';
      if ($term->hasField('field_service_bundle')
          && !$term->get('field_service_bundle')->isEmpty()) {
        $bundle = trim((string) $term->get('field_service_bundle')->value);
        $bundle_info = \Drupal::service('entity_type.bundle.info')
          ->getBundleInfo('estimate');
        if (!isset($bundle_info[$bundle])) {
          \Drupal::logger('wo_project_pipeline')->warning(
            'Component term @tid has unknown estimate bundle @bundle — falling back to landscaping.',
            ['@tid' => $tid, '@bundle' => $bundle]
          );
          $bundle = 'landscaping';
        }
      }

      // Get assigned_to from term's field_default_estimator,
      // fallback to container's field_assigned_to.
      $assigned_to = NULL;
      if ($term->hasField('field_default_estimator')
          && !$term->get('field_default_estimator')->isEmpty()) {
        $assigned_to = (int) $term->get('field_default_estimator')->target_id;
      }
      elseif (!$container->get('field_assigned_to')->isEmpty()) {
        $assigned_to = (int) $container->get('field_assigned_to')->target_id;
      }

      $scope = 'Client is requesting a Landscaping project with '
        . $component_name
        . ' included. Please review and update this scope summary'
        . ' with specific project details.';

      // Build values with only fields that exist on this bundle.
      $estimate_fields = \Drupal::service('entity_field.manager')
        ->getFieldDefinitions('estimate', $bundle);

      $values = [
        'type' => $bundle,
        'uid' => $current_uid,
      ];

      // Conditionally set fields based on bundle support.
      $conditional_fields = [
        'field_estimate_request' => $container->get('field_estimate_request')->target_id,
        'field_estimate_type' => $tid,
        'field_stage' => 1415,
        'field_is_current_revision' => TRUE,
        'field_revision_number' => 1,
        'field_scope_summary' => $scope,
        'field_parent_estimate' => $estimate_id,
        'field_is_container' => FALSE,
      ];

      foreach ($conditional_fields as $field_name => $field_value) {
        if (isset($estimate_fields[$field_name])) {
          $values[$field_name] = $field_value;
        }
      }

      if ($assigned_to) {
        $values['field_assigned_to'] = $assigned_to;
      }

      $component_estimate = $estimate_storage->create($values);
      $component_estimate->save();
    }

    $response->addCommand(new CloseModalDialogCommand());
    $response->addCommand(new RedirectCommand('/estimate/' . $estimate_id));
    return $response;
  }

  /**
   * AJAX cancel handler — closes modal.
   */
  public function cancelAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

}
