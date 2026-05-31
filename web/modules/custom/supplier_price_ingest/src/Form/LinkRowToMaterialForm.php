<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\supplier_price_ingest\Form\IngestRowFormTrait;
use Drupal\wo_material_price_sync\Service\PriceSyncService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Phase 3.7 — link a discovery row to an existing material.
 *
 * Route: /admin/materials/supplier-ingest/discovery/{row}/link-existing
 *
 * On submit: PriceSyncService::ingestRow() with the chosen material,
 * source = 'feed_import_reviewed'. The unified mutation path means
 * the chosen link gets the same threshold treatment (apply / flag
 * for review) that auto-committed rows get.
 */
class LinkRowToMaterialForm extends FormBase {

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
    return 'supplier_price_ingest_link_row_to_material';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $supplier_price_ingest_row = NULL): array {
    $this->row = $supplier_price_ingest_row;
    if (!$this->row) {
      $form['error'] = ['#markup' => $this->t('No row loaded.')];
      return $form;
    }

    $form['back_link'] = $this->buildBackToQueueLink(self::CTX_DISCOVERY);
    $this->attachRowFormLibrary($form);

    $form['row_summary'] = $this->buildRowSummary($this->row);

    $form['material'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'material',
      '#title' => $this->t('Link to existing material'),
      '#required' => TRUE,
      '#description' => $this->t('Type to search BOS materials. Discontinued materials are blocked at submit — pick a current SKU.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Link to Material'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromUserInput('/admin/materials/supplier-ingest/discovery'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $matId = (int) $form_state->getValue('material');
    if ($matId <= 0) {
      $form_state->setErrorByName('material', $this->t('Select a material.'));
      return;
    }
    $material = $this->entityTypeManager->getStorage('material')->load($matId);
    if (!$material) {
      $form_state->setErrorByName('material', $this->t('Selected material does not exist.'));
      return;
    }
    if ($material->hasField('field_discontinued') && (bool) ($material->get('field_discontinued')->value ?? FALSE)) {
      $form_state->setErrorByName(
        'material',
        $this->t('Material "@label" is discontinued. Pick a current material — or use the Mark-as-Replacement workflow if this row IS the replacement for the discontinued one.', [
          '@label' => $material->label(),
        ]),
      );
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $row = $this->row;
    if (!$row) {
      return;
    }
    $matId = (int) $form_state->getValue('material');
    $material = $this->entityTypeManager->getStorage('material')->load($matId);
    $batch = $row->get('field_batch')->entity;
    $supplier = $batch ? $batch->get('field_supplier')->entity : NULL;
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
      $this->messenger()->addError($this->t('Link failed: @msg', ['@msg' => $outcome->message]));
      return;
    }

    $row->set('field_row_status', 'discovery_resolved');
    $row->set('field_resolution_action', 'linked_to_existing_material');
    $row->set('field_matched_material', $material->id());
    $row->set('field_resolved_by', $this->currentUser->id());
    $row->set('field_resolved_on', gmdate('Y-m-d\TH:i:s'));
    $this->appendNote(
      $row,
      sprintf('Linked to existing material #%d (%s) by %s. PriceSync outcome: %s.',
        $material->id(), $material->label(), $this->currentUser->getDisplayName(), $outcome->message),
    );
    $row->save();

    $this->messenger()->addStatus($this->t('Row linked to @label.', ['@label' => $material->label()]));
    $form_state->setRedirectUrl($this->nextRowRedirect(
      $this->row,
      'supplier_price_ingest.discovery_link_existing',
      self::CTX_DISCOVERY,
      $this->entityTypeManager,
      $this->messenger(),
    ));
  }

}
