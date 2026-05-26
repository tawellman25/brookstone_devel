<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\supplier_price_ingest\Service\IngestMatcher;
use Drupal\wo_material_price_sync\Service\PriceSyncService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Phase 3.7 — mark a discovery row as the replacement for a
 * discontinued BOS material.
 *
 * Route: /admin/materials/supplier-ingest/discovery/{row}/mark-replacement
 *
 * Two-mode form:
 *   - "Use existing material": reviewer picks a current material
 *     and that becomes the replacement target.
 *   - "Create new material from this row": composes the
 *     CreateMaterialFromRow flow inline; the freshly-created
 *     material becomes the replacement target.
 *
 * On submit (one DB transaction):
 *   - Resolve / create the replacement material.
 *   - Set the discontinued material's field_replaced_by to the
 *     replacement's id.
 *   - Hand off to PriceSyncService::ingestRow() for the link/audit
 *     write (source='feed_import_reviewed').
 *   - Stamp ingest row: discovery_resolved + marked_as_replacement.
 */
class MarkRowAsReplacementForm extends FormBase {

  use IngestRowFormTrait;

  private ?EntityInterface $row = NULL;
  private array $bundleOptions = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PriceSyncService $priceSync,
    private readonly IngestMatcher $matcher,
    private readonly AccountInterface $currentUser,
    private readonly Connection $database,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('wo_material_price_sync.price_sync'),
      $container->get('supplier_price_ingest.matcher'),
      $container->get('current_user'),
      $container->get('database'),
    );
  }

  public function getFormId(): string {
    return 'supplier_price_ingest_mark_row_as_replacement';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $supplier_price_ingest_row = NULL): array {
    $this->row = $supplier_price_ingest_row;
    if (!$this->row) {
      $form['error'] = ['#markup' => $this->t('No row loaded.')];
      return $form;
    }

    $form['row_summary'] = $this->buildRowSummary($this->row);

    $form['discontinued_material'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'material',
      '#title' => $this->t('Discontinued material this row replaces'),
      '#required' => TRUE,
      '#description' => $this->t('Type to search BOS materials. Only materials with field_discontinued = TRUE are valid choices — submit will reject non-discontinued picks.'),
    ];

    $form['replacement_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Replacement material source'),
      '#required' => TRUE,
      '#options' => [
        'existing' => $this->t('Use an existing material'),
        'new'      => $this->t('Create a new material from this row'),
      ],
      '#default_value' => 'existing',
    ];

    $form['existing_replacement'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'material',
      '#title' => $this->t('Replacement material (existing)'),
      '#description' => $this->t('Pick the current material that supersedes the discontinued one. Cannot itself be discontinued.'),
      '#states' => [
        'visible' => [':input[name="replacement_mode"]' => ['value' => 'existing']],
        'required' => [':input[name="replacement_mode"]' => ['value' => 'existing']],
      ],
    ];

    // "Create new from row" sub-fields — same shape as CreateMaterialFromRowForm.
    $bundleInfo = \Drupal::service('entity_type.bundle.info')->getBundleInfo('material');
    $bundles = [];
    foreach ($bundleInfo as $machine => $info) {
      $bundles[$machine] = $info['label'] . " ({$machine})";
    }
    ksort($bundles);
    $this->bundleOptions = $bundles;

    $description = (string) ($this->row->get('field_description')->value ?? '');
    $inferred = $this->matcher->inferCandidateBundles($description);

    $form['new_replacement'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('New material details (used when "Create new" is selected)'),
      '#states' => [
        'visible' => [':input[name="replacement_mode"]' => ['value' => 'new']],
      ],
    ];
    $form['new_replacement']['new_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Bundle'),
      '#options' => $bundles,
      '#default_value' => $inferred[0] ?? '',
      '#empty_option' => $this->t('- Select -'),
    ];
    $form['new_replacement']['new_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $description,
      '#maxlength' => 255,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Mark as Replacement'),
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
    $discId = (int) $form_state->getValue('discontinued_material');
    if ($discId <= 0) {
      $form_state->setErrorByName('discontinued_material', $this->t('Pick the discontinued material this row replaces.'));
      return;
    }
    $disc = $this->entityTypeManager->getStorage('material')->load($discId);
    if (!$disc) {
      $form_state->setErrorByName('discontinued_material', $this->t('Discontinued material no longer exists.'));
      return;
    }
    if (!$disc->hasField('field_discontinued') || !(bool) ($disc->get('field_discontinued')->value ?? FALSE)) {
      $form_state->setErrorByName(
        'discontinued_material',
        $this->t('Material "@l" is not flagged as discontinued. Mark-as-Replacement only applies to discontinued materials.', ['@l' => $disc->label()]),
      );
    }

    $mode = (string) $form_state->getValue('replacement_mode');
    if ($mode === 'existing') {
      $repId = (int) $form_state->getValue('existing_replacement');
      if ($repId <= 0) {
        $form_state->setErrorByName('existing_replacement', $this->t('Pick the existing replacement material.'));
        return;
      }
      if ($repId === $discId) {
        $form_state->setErrorByName('existing_replacement', $this->t('Replacement cannot be the same as the discontinued material.'));
        return;
      }
      $rep = $this->entityTypeManager->getStorage('material')->load($repId);
      if (!$rep) {
        $form_state->setErrorByName('existing_replacement', $this->t('Replacement material does not exist.'));
      }
      elseif ($rep->hasField('field_discontinued') && (bool) ($rep->get('field_discontinued')->value ?? FALSE)) {
        $form_state->setErrorByName('existing_replacement', $this->t('Replacement material "@l" is itself discontinued — pick a current material.', ['@l' => $rep->label()]));
      }
    }
    elseif ($mode === 'new') {
      $bundle = (string) $form_state->getValue('new_bundle');
      if ($bundle === '' || !isset($this->bundleOptions[$bundle])) {
        $form_state->setErrorByName('new_bundle', $this->t('Pick a bundle for the new material.'));
      }
      $title = trim((string) $form_state->getValue('new_title'));
      if ($title === '') {
        $form_state->setErrorByName('new_title', $this->t('Title is required for the new material.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $row = $this->row;
    if (!$row) {
      return;
    }
    $batch = $row->get('field_batch')->entity;
    $supplier = $batch ? $batch->get('field_supplier')->entity : NULL;
    if (!$supplier) {
      $this->messenger()->addError($this->t('Internal error: row has no resolvable supplier.'));
      return;
    }

    $discId = (int) $form_state->getValue('discontinued_material');
    $disc = $this->entityTypeManager->getStorage('material')->load($discId);
    $mode = (string) $form_state->getValue('replacement_mode');

    $tx = $this->database->startTransaction();
    try {
      // Step 1 — resolve / create the replacement material.
      if ($mode === 'existing') {
        $rep = $this->entityTypeManager->getStorage('material')->load((int) $form_state->getValue('existing_replacement'));
      }
      else {
        $bundle = (string) $form_state->getValue('new_bundle');
        $title  = trim((string) $form_state->getValue('new_title'));
        $values = [
          'type' => $bundle, 'uid' => $this->currentUser->id(), 'title' => $title,
          'field_name' => $title, 'field_description' => (string) ($row->get('field_description')->value ?? ''),
        ];
        if ($mfrItemNum = trim((string) ($row->get('field_manufacturer_item_number')->value ?? ''))) {
          $values['field_manufacturer_item_number'] = $mfrItemNum;
        }
        $rep = $this->entityTypeManager->getStorage('material')->create($values);
        $rep->save();
        $rep = $this->entityTypeManager->getStorage('material')->load($rep->id());
      }

      // Step 2 — set field_replaced_by on the discontinued material.
      if (!$disc->hasField('field_replaced_by')) {
        throw new \RuntimeException('Discontinued material lacks field_replaced_by; cannot mark as replacement.');
      }
      $disc->set('field_replaced_by', $rep->id());
      $disc->save();

      // Step 3 — link replacement material to supplier + write audit.
      $outcome = $this->priceSync->ingestRow(
        $rep,
        $supplier,
        $this->buildRowData($row),
        'feed_import_reviewed',
        (int) $batch->id(),
      );
      if ($outcome->status === 'error') {
        throw new \RuntimeException($outcome->message);
      }

      // Step 4 — stamp the ingest row.
      $row->set('field_row_status', 'discovery_resolved');
      $row->set('field_resolution_action', 'marked_as_replacement');
      $row->set('field_matched_material', $rep->id());
      $row->set('field_resolved_by', $this->currentUser->id());
      $row->set('field_resolved_on', gmdate('Y-m-d\TH:i:s'));
      $this->appendNote(
        $row,
        sprintf("Marked as replacement for discontinued material #%d ('%s'). Replacement: #%d (%s) [%s]. By %s.",
          $disc->id(), $disc->label(), $rep->id(), $rep->label(),
          $mode === 'new' ? 'created from this row' : 'existing material',
          $this->currentUser->getDisplayName()),
      );
      $row->save();

      $this->messenger()->addStatus($this->t(
        'Marked as replacement: discontinued #@d ("@dl") → current #@r ("@rl").',
        ['@d' => $disc->id(), '@dl' => $disc->label(), '@r' => $rep->id(), '@rl' => $rep->label()],
      ));
      $form_state->setRedirectUrl(Url::fromUserInput('/admin/materials/supplier-ingest/discovery'));
    }
    catch (\Throwable $e) {
      $tx->rollBack();
      \Drupal::logger('supplier_price_ingest')->error(
        'Mark-as-replacement failed for row @rid: @cls @msg',
        ['@rid' => $row->id(), '@cls' => get_class($e), '@msg' => $e->getMessage()],
      );
      $this->messenger()->addError($this->t('Mark-as-replacement failed: @msg. No changes were saved.', ['@msg' => $e->getMessage()]));
      $form_state->setRebuild(TRUE);
    }
  }

}
