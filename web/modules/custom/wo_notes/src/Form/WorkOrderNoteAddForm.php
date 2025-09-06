<?php

namespace Drupal\wo_notes\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding Work Order Notes.
 */
class WorkOrderNoteAddForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new WorkOrderNoteAddForm.
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
    return 'work_order_note_add_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $work_order = NULL) {
    $form['#attributes']['class'][] = 'work-order-note-form';
    $form['#cache'] = ['max-age' => 0]; // Disable caching for speed.

    // Hidden field for work_order ID.
    $form['work_order_id'] = [
      '#type' => 'hidden',
      '#value' => $work_order,
    ];

    // Note text field.
    $form['field_note_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Note'),
      '#required' => TRUE,
      '#attributes' => ['autofocus' => TRUE], // Mobile-friendly.
    ];

    // Submit button with AJAX.
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save Note'),
        '#ajax' => [
          'callback' => '::ajaxSubmitCallback',
          'wrapper' => 'work-order-note-form',
        ],
      ],
    ];

    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $note = $this->entityTypeManager->getStorage('wo_notes')->create([
      'bundle' => 'note',
      'field_note_text' => $form_state->getValue('field_note_text'),
      'field_work_order' => $form_state->getValue('work_order_id'),
      'uid' => $this->currentUser()->id(), // Use ECK's built-in uid.
      'created' => time(), // Use ECK's built-in created.
    ]);
    $note->save();
    \Drupal::messenger()->addStatus($this->t('Note added successfully.'));
    $form_state->setRebuild(FALSE);
  }

  /**
   * AJAX callback for form submission.
   */
  public function ajaxSubmitCallback(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(new CloseModalDialogCommand());
    $destination = $form_state->getValue('destination') ?: \Drupal\Core\Url::fromRoute('entity.work_order.canonical', ['work_order' => $form_state->getValue('work_order_id')])->toString();
    $response->addCommand(new RedirectCommand($destination));
    return $response;
  }

}