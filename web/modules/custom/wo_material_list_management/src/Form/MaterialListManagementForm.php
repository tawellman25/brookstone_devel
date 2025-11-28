<?php

namespace Drupal\wo_material_list_management\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\eck\Entity\EckEntity;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Serialization\Json;

class MaterialListManagementForm extends FormBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new form.
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
    return 'material_list_management_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\eck\Entity\EckEntity|null $wo_material_list
   *   The material list entity if passed in; otherwise NULL.
   */
  public function buildForm(array $form, FormStateInterface $form_state, EckEntity $wo_material_list = NULL) {
    // Log the form build.
    \Drupal::logger('wo_material_list_management')->notice(
      'Building form for material list ID: @id',
      ['@id' => $wo_material_list ? $wo_material_list->id() : 'No ID']
    );

    // Hidden value for list ID.
    $form['list_id'] = [
      '#type' => 'value',
      '#value' => $wo_material_list ? $wo_material_list->id() : NULL,
    ];

    // Items table.
    $form['items'] = [
      '#type' => 'table',
      '#header' => [
        $this->t(''),
        $this->t('#'),
        $this->t('Parts Used/Description'),
        $this->t('Subtotal'),
        $this->t(''),
      ],
      '#empty' => $this->t('No items found'),
    ];

    // Load existing items.
    $items = $this->entityTypeManager->getStorage('wo_material_list_item')
      ->loadByProperties(['field_list_id' => ['target_id' => $wo_material_list->id()]]);

    foreach ($items as $item) {
      $label = $item->get('field_alternate_name_description')->value ?? 'Unnamed Item';
      $form['items'][$item->id()] = [
        'select' => [
          '#type' => 'checkbox',
          '#return_value' => $item->id(),
          '#parents' => ['items', $item->id(), 'select'],
          '#default_value' => FALSE,
        ],
        'quantity' => [
          '#markup' => $item->get('field_quantity')->value,
        ],
        'parts_used' => [
          '#markup' => $this->getPartsUsedLabel($item) ?: $label,
        ],
        'subtotal_with_markup' => [
          '#markup' => $item->get('field_subtotal_w_markup')->value,
        ],
        'edit' => [
          '#type' => 'link',
          '#title' => $this->t('Edit'),
          '#url' => Url::fromUri('internal:/wo_material_list_item/' . $item->id() . '/edit', [
            'query' => ['destination' => \Drupal::service('path.current')->getPath()],
          ]),
          '#attributes' => ['class' => ['button', 'button--small']],
        ],
      ];
    }

    // Actions container.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    // Add Item link (modal).
    $form['actions']['add_item'] = [
      '#type' => 'link',
      '#title' => $this->t('Add Item'),
      '#url' => Url::fromUri('internal:/admin/content/wo_material_list_item/add/items', [
        'query' => [
          'edit[field_list_id][widget][0][target_id]' => $wo_material_list->id(),
          'destination' => '/wo_material_list/' . $wo_material_list->id(),
        ],
      ]),
      '#attributes' => [
        'class' => ['button', 'button--primary', 'use-ajax'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => Json::encode(['width' => 800]),
      ],
      '#attached' => ['library' => ['core/drupal.dialog.ajax']],
      '#weight' => 0,
    ];

    // Clone Items link opens modal.
    $form['actions']['clone_list'] = [
      '#type' => 'link',
      '#title' => $this->t('Clone Items'),
      '#url' => Url::fromRoute(
        'wo_material_list_management.clone_items_modal',
        ['wo_material_list' => $wo_material_list->id()]
      ),
      '#attributes' => [
        'class' => ['use-ajax', 'button', 'button--primary'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => Json::encode(['width' => 600]),
      ],
      '#attached' => ['library' => ['core/drupal.dialog.ajax']],
      '#weight' => 2,
    ];

    // Delete selected items if permission allows.
    $current_user = \Drupal::currentUser();
    if ($current_user->hasPermission('delete any wo_material_list_item entity') ||
        $current_user->hasPermission('delete own wo_material_list_item entity')) {
      $form['actions']['delete_selected'] = [
        '#type' => 'submit',
        '#value' => $this->t('Delete Items'),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'js-form-submit', 'form-submit'],
        ],
        '#submit' => ['::deleteSelectedSubmit'],
        '#limit_validation_errors' => [['items']],
        '#weight' => 1,
      ];
    }

    return $form;
  }

  /**
   * Helper for label.
   */
  private function getPartsUsedLabel(EckEntity $item) {
    $parts_used = $item->get('field_parts_used')->referencedEntities();
    $labels = [];
    foreach ($parts_used as $part) {
      if ($part->hasField('title') && !$part->get('title')->isEmpty()) {
        $labels[] = $part->get('title')->value;
      }
    }
    return implode(', ', $labels);
  }

  /**
   * Delete selected items handler.
   */
  public function deleteSelectedSubmit(array &$form, FormStateInterface $form_state) {
    // ... existing logic ...
  }

  /**
   * Clone items inline (if still used).
   */
  public function cloneListSubmit(array &$form, FormStateInterface $form_state) {
    // ... existing logic ...
  }

  /**
   * Check entity ownership.
   */
  private function isOwnEntity(EckEntity $entity, AccountInterface $account) {
    return $entity->getOwnerId() === $account->id();
  }

  /**
   * Default submit (unused).
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // No-op.
  }

}


