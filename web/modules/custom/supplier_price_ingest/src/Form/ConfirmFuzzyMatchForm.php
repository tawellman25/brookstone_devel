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
 * Phase 3.7 — confirm a Tier 3 medium-confidence fuzzy match.
 *
 * Route: /admin/materials/supplier-ingest/fuzzy-review/{row}/confirm
 *
 * Calls PriceSyncService::ingestRow() with the matcher's proposed
 * material and source='feed_import_reviewed'. The row transitions
 * to field_row_status='committed' (not discovery_resolved — confirm
 * is a commit, not a discovery decision).
 */
class ConfirmFuzzyMatchForm extends FormBase {

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
    return 'supplier_price_ingest_confirm_fuzzy_match';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $supplier_price_ingest_row = NULL): array {
    $this->row = $supplier_price_ingest_row;
    if (!$this->row) {
      $form['error'] = ['#markup' => $this->t('No row loaded.')];
      return $form;
    }

    $form['back_link'] = $this->buildBackToQueueLink(self::CTX_FUZZY_REVIEW);
    $this->attachRowFormLibrary($form);

    $form['row_summary'] = $this->buildRowSummary($this->row);

    $matched = $this->row->get('field_matched_material')->entity;
    if (!$matched) {
      $form['no_match'] = [
        '#type' => 'item',
        '#markup' => '<div class="messages messages--warning">' . $this->t('This row has no proposed match to confirm. Use Override Match or Send to Discovery instead.') . '</div>',
      ];
      return $form;
    }
    $score = (string) ($this->row->get('field_match_confidence')->value ?? '');

    $form['proposed'] = [
      '#type' => 'item',
      '#title' => $this->t('Proposed match'),
      '#markup' => $this->t('<strong>@l</strong> (material #@i) — confidence <strong>@s%</strong>', [
        '@l' => $matched->label(), '@i' => $matched->id(), '@s' => $score,
      ]),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirm and Commit'),
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

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $row = $this->row;
    if (!$row) {
      return;
    }
    $matched = $row->get('field_matched_material')->entity;
    $batch = $row->get('field_batch')->entity;
    $supplier = $batch ? $batch->get('field_supplier')->entity : NULL;
    if (!$matched || !$supplier) {
      $this->messenger()->addError($this->t('Missing matched material or supplier; cannot commit.'));
      return;
    }
    if ($matched->hasField('field_discontinued') && (bool) ($matched->get('field_discontinued')->value ?? FALSE)) {
      $this->messenger()->addError($this->t(
        'Matched material "@l" was marked discontinued after the matcher ran. Use Override or Send to Discovery.',
        ['@l' => $matched->label()],
      ));
      return;
    }

    $outcome = $this->priceSync->ingestRow(
      $matched,
      $supplier,
      $this->buildRowData($row),
      'feed_import_reviewed',
      (int) $batch->id(),
    );
    if ($outcome->status === 'error') {
      $this->messenger()->addError($this->t('Confirm failed: @msg', ['@msg' => $outcome->message]));
      return;
    }

    $row->set('field_row_status', 'committed');
    $row->set('field_resolution_action', $outcome->status === 'auto_created' ? 'created_link' : 'updated_link');
    $row->set('field_resolved_by', $this->currentUser->id());
    $row->set('field_resolved_on', gmdate('Y-m-d\TH:i:s'));
    $this->appendNote($row, sprintf('Fuzzy match confirmed by %s. PriceSync outcome: %s.', $this->currentUser->getDisplayName(), $outcome->message));
    $row->save();

    $this->messenger()->addStatus($this->t('Confirmed match → @l.', ['@l' => $matched->label()]));
    $form_state->setRedirectUrl($this->nextRowRedirect(
      $this->row,
      'supplier_price_ingest.fuzzy_confirm',
      self::CTX_FUZZY_REVIEW,
      $this->entityTypeManager,
      $this->messenger(),
    ));
  }

}
