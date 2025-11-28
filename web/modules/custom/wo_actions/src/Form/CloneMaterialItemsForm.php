<?php

namespace Drupal\wo_actions\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CloneMaterialItemsForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a CloneMaterialItemsForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'clone_material_items_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // No need to load a specific entity here since we're just selecting a new list

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New List Title'),
      '#required' => TRUE,
      '#default_value' => 'New Clone', // Default title for the new cloned list
    ];

    $form['list_reference'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Select Existing Material List'),
      '#target_type' => 'wo_material_list',
      '#selection_settings' => [
        'target_bundles' => NULL,
      ],
      '#selection_handler' => 'default:wo_material_list',
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clone'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entity_ids_to_clone = \Drupal::service('session')->get('entity_ids_to_clone', []);

    if (empty($entity_ids_to_clone)) {
      $this->messenger()->addError($this->t('No entities found to clone.'));
      return;
    }

    $new_list_id = $form_state->getValue('list_reference');
    $new_list = $this->entityTypeManager->getStorage('wo_material_list')->load($new_list_id);
    if (!$new_list) {
      $this->messenger()->addError($this->t('The selected Material List does not exist.'));
      return;
    }

    $cloned_count = 0;
    foreach ($entity_ids_to_clone as $entity_id) {
      $entity_to_clone = $this->entityTypeManager->getStorage('wo_material_list_item')->load($entity_id);
      if ($entity_to_clone) {
        $new_item = $entity_to_clone->createDuplicate();
        $new_item->set('field_list_id', $new_list_id);
        $new_item->save();
        $cloned_count++;
      }
    }

    $this->messenger()->addMessage($this->t('Cloned %count items into the material list %list_title.', [
      '%count' => $cloned_count,
      '%list_title' => $new_list->label(),
    ]));

    // Clean up session data
    \Drupal::service('session')->remove('entity_ids_to_clone');
  }

}