<?php
namespace Drupal\wo_material_list_management\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Drupal\eck\Entity\EckEntity;

class CloneItemsModalForm extends FormBase {
  protected $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container) {
    return new static($container->get('entity_type.manager'));
  }

  public function getFormId() {
    return 'clone_items_modal_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, EckEntity $wo_material_list = NULL) {
    $form['list_id'] = [
      '#type' => 'value',
      '#value' => $wo_material_list->id(),
    ];

    $form['target_list'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Select Existing Material List'),
      '#target_type' => 'wo_material_list',
      '#selection_handler' => 'default:wo_material_list',
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clone'),
    ];

    // Attach the dialog library
    $form['#attached']['library'][] = 'core/drupal.dialog';
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $source_id = $form_state->getValue('list_id');
    $target_id = $form_state->getValue('target_list');
    $items = $this->entityTypeManager
      ->getStorage('wo_material_list_item')
      ->loadByProperties(['field_list_id' => ['target_id' => $source_id]]);

    foreach ($items as $item) {
      $clone = $item->createDuplicate();
      $clone->set('field_list_id', ['target_id' => $target_id]);
      $clone->save();
    }

    // Build AJAX response to close modal and reload
    $response = new AjaxResponse();
    $response->addCommand(new CloseModalDialogCommand());
    $response->addCommand(new RedirectCommand(Url::fromRoute('<current>')->toString()));

    // Tell Drupal to use this AJAX response
    $form_state->setResponse($response);
  }
}