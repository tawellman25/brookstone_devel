<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\wo_material_price_sync\Service\PriceSyncService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Phase 3.7 — override a Tier 3 medium-confidence fuzzy match with
 * a different material picked by the reviewer.
 *
 * Route: /admin/materials/supplier-ingest/fuzzy-review/{row}/override
 *
 * Mirrors LinkRowToMaterialForm but for the fuzzy-review surface:
 * captures the overridden choice in the resolution_notes audit trail
 * and transitions the row to 'committed' (not discovery_resolved).
 */
class OverrideFuzzyMatchForm extends FormBase {

  use IngestRowFormTrait;

  private ?EntityInterface $row = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PriceSyncService $priceSync,
    protected AccountInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('wo_material_price_sync.price_sync'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'supplier_price_ingest_override_fuzzy_match';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $supplier_price_ingest_row = NULL): array {
    $this->row = $supplier_price_ingest_row;
    if (!$this->row) {
      $form['error'] = ['#markup' => $this->t('No row loaded.')];
      return $form;
    }

    $form['row_summary'] = $this->buildRowSummary($this->row);

    $proposed = $this->row->get('field_matched_material')->entity;
    if ($proposed) {
      $form['proposed'] = [
        '#type' => 'item',
        '#title' => $this->t('Proposed match (will be overridden)'),
        '#markup' => $this->t('<strong>@l</strong> (#@i) — confidence @s%', [
          '@l' => $proposed->label(), '@i' => $proposed->id(),
          '@s' => (string) ($this->row->get('field_match_confidence')->value ?? ''),
        ]),
      ];
    }

    $form['material'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'material',
      '#title' => $this->t('Correct material (replaces the proposed match)'),
      '#required' => TRUE,
      '#description' => $this->t('Type to search. Discontinued materials are blocked at submit.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Override and Commit'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromUserInput('/admin/materials/supplier-ingest/fuzzy-review'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $matId = (int) $form_state->getValue('material');
    if ($matId <= 0) {
      $form_state->setErrorByName('material', $this->t('Pick a material.'));
      return;
    }
    $material = $this->entityTypeManager->getStorage('material')->load($matId);
    if (!$material) {
      $form_state->setErrorByName('material', $this->t('Selected material does not exist.'));
      return;
    }
    if ($material->hasField('field_discontinued') && (bool) ($material->get('field_discontinued')->value ?? FALSE)) {
      $form_state->setErrorByName('material', $this->t('Material "@l" is discontinued; pick a current one.', ['@l' => $material->label()]));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $row = $this->row;
    if (!$row) {
      return;
    }
    $material = $this->entityTypeManager->getStorage('material')->load((int) $form_state->getValue('material'));
    $batch = $row->get('field_batch')->entity;
    $supplier = $batch ? $batch->get('field_supplier')->entity : NULL;
    $proposed = $row->get('field_matched_material')->entity;
    if (!$material || !$supplier) {
      $this->messenger()->addError($this->t('Internal error: missing material or supplier.'));
      return;
    }

    $outcome = $this->priceSync->ingestRow(
      $material,
      $supplier,
      $this->buildRowData($row),
      'feed_import_reviewed',
      (int) $batch->id(),
    );
    if ($outcome->status === 'error') {
      $this->messenger()->addError($this->t('Override failed: @msg', ['@msg' => $outcome->message]));
      return;
    }

    $row->set('field_row_status', 'committed');
    $row->set('field_matched_material', $material->id());
    $row->set('field_resolution_action', $outcome->status === 'auto_created' ? 'created_link' : 'updated_link');
    $row->set('field_resolved_by', $this->currentUser->id());
    $row->set('field_resolved_on', gmdate('Y-m-d\TH:i:s'));
    $proposedLabel = $proposed ? sprintf('#%d (%s)', $proposed->id(), $proposed->label()) : '(none)';
    $this->appendNote(
      $row,
      sprintf('Fuzzy match OVERRIDDEN by %s. Proposed: %s. Chosen: #%d (%s). PriceSync outcome: %s.',
        $this->currentUser->getDisplayName(), $proposedLabel, $material->id(), $material->label(), $outcome->message),
    );
    $row->save();

    $this->messenger()->addStatus($this->t('Override committed → @l.', ['@l' => $material->label()]));
    $form_state->setRedirectUrl(Url::fromUserInput('/admin/materials/supplier-ingest/fuzzy-review'));
  }

}
