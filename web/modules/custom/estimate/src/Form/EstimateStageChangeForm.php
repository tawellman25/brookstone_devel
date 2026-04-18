<?php

declare(strict_types=1);

namespace Drupal\estimate\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Inline form for changing an estimate's stage from the view page.
 */
final class EstimateStageChangeForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'estimate_stage_change_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $estimate = NULL): array {
    if (!$estimate || $estimate->getEntityTypeId() !== 'estimate') {
      return $form;
    }

    $form['#attributes']['class'][] = 'estimate-stage-change-form';

    $estimate_id = (int) $estimate->id();
    $form['estimate_id'] = [
      '#type' => 'hidden',
      '#value' => $estimate_id,
    ];

    // Current stage.
    $current_tid = 0;
    if ($estimate->hasField('field_stage') && !$estimate->get('field_stage')->isEmpty()) {
      $current_tid = (int) $estimate->get('field_stage')->target_id;
    }

    // Load all estimate_stage terms.
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree('estimate_stage', 0, NULL, TRUE);
    $options = [];
    foreach ($terms as $term) {
      $options[(int) $term->id()] = $term->label();
    }

    $form['stage'] = [
      '#type' => 'select',
      '#title' => $this->t('Stage'),
      '#options' => $options,
      '#default_value' => $current_tid,
    ];

    // Show WO link if already converted.
    if ($estimate->hasField('field_work_order') && !$estimate->get('field_work_order')->isEmpty()) {
      $wo = $estimate->get('field_work_order')->entity;
      if ($wo) {
        $form['work_order_link'] = [
          '#type' => 'markup',
          '#markup' => '<p class="estimate-wo-link"><strong>' . $this->t('Work Order:') . '</strong> '
            . '<a href="' . $wo->toUrl('canonical')->toString() . '">'
            . htmlspecialchars($wo->label() ?: ('WO #' . $wo->id()))
            . '</a></p>',
        ];
      }
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update Stage'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $estimate_id = (int) $form_state->getValue('estimate_id');
    $new_stage_tid = (int) $form_state->getValue('stage');

    $estimate = $this->entityTypeManager->getStorage('estimate')->load($estimate_id);
    if (!$estimate) {
      $this->messenger()->addError($this->t('Estimate not found.'));
      return;
    }

    $old_tid = (int) ($estimate->get('field_stage')->target_id ?? 0);
    if ($old_tid === $new_stage_tid) {
      $this->messenger()->addStatus($this->t('Stage unchanged.'));
      return;
    }

    $estimate->set('field_stage', ['target_id' => $new_stage_tid]);
    $estimate->save();

    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($new_stage_tid);
    $label = $term ? $term->label() : (string) $new_stage_tid;

    $this->messenger()->addStatus($this->t('Stage updated to @stage.', ['@stage' => $label]));
  }

}
