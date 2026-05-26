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
 * Phase 3.7 — create a new material from a discovery row.
 *
 * Route: /admin/materials/supplier-ingest/discovery/{row}/create-material
 *
 * Pre-fills the bundle from Phase 3.4's keyword-based inference and
 * the title/mfr-item-#/manufacturer/uom fields from the row data.
 * Reviewer can adjust before save. Full bundle-specific material
 * fields are NOT exposed here — the reviewer can edit those on the
 * material's standard edit form afterward; the create form keeps
 * the surface minimal.
 *
 * On submit (single DB transaction):
 *   1. Create the material entity.
 *   2. Hand off to PriceSyncService::ingestRow() with
 *      source='feed_import_reviewed' — creates the material_suppliers
 *      link and writes the audit history entry.
 *   3. Stamp the ingest row as discovery_resolved.
 */
class CreateMaterialFromRowForm extends FormBase {

  use IngestRowFormTrait;

  /**
   * Material bundles users can target. Drawn live from
   * entity.bundle.info so any newly-added bundle (e.g.,
   * `bulk_material` in 2026-05-24) shows up automatically.
   */
  private array $bundleOptions = [];

  private ?EntityInterface $row = NULL;

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
    return 'supplier_price_ingest_create_material_from_row';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $supplier_price_ingest_row = NULL): array {
    $this->row = $supplier_price_ingest_row;
    if (!$this->row) {
      $form['error'] = ['#markup' => $this->t('No row loaded.')];
      return $form;
    }

    $form['row_summary'] = $this->buildRowSummary($this->row);

    // Build bundle dropdown — every material bundle, alphabetically.
    $bundleInfo = \Drupal::service('entity_type.bundle.info')->getBundleInfo('material');
    $bundles = [];
    foreach ($bundleInfo as $machine => $info) {
      $bundles[$machine] = $info['label'] . " ({$machine})";
    }
    ksort($bundles);
    $this->bundleOptions = $bundles;

    // Default bundle from Phase 3.4 inference.
    $description = (string) ($this->row->get('field_description')->value ?? '');
    $inferred = $this->matcher->inferCandidateBundles($description);
    $defaultBundle = $inferred[0] ?? '';

    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Material bundle'),
      '#options' => $bundles,
      '#required' => TRUE,
      '#default_value' => $defaultBundle,
      '#empty_option' => $this->t('- Select -'),
      '#description' => $inferred
        ? $this->t('Phase 3.4 inferred: <code>@list</code> — first one pre-selected.', ['@list' => implode(', ', $inferred)])
        : $this->t('No bundle inferred from description; pick one.'),
    ];

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Material title'),
      '#required' => TRUE,
      '#default_value' => $description !== '' ? $description : '',
      '#maxlength' => 255,
      '#description' => $this->t('AEL-managed bundles (irrigation, decorative_rock, weeds) will override this title from field_size + field_name on save — that\'s fine, the row above pre-fills field_name with the same value.'),
    ];

    $form['field_manufacturer_item_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Manufacturer item #'),
      '#default_value' => (string) ($this->row->get('field_manufacturer_item_number')->value ?? ''),
      '#description' => $this->t('Drives future Tier 1 matches — leave populated whenever the row has it.'),
    ];

    // Manufacturer reference — pre-resolved from row's manufacturer_name.
    $rowMfrName = trim((string) ($this->row->get('field_manufacturer_name')->value ?? ''));
    $defaultMfr = NULL;
    if ($rowMfrName !== '') {
      $mfrIds = $this->entityTypeManager->getStorage('manufacturer')->getQuery()
        ->accessCheck(FALSE)
        ->condition('title', $rowMfrName, '=')
        ->sort('id', 'ASC')
        ->range(0, 1)
        ->execute();
      if ($mfrIds) {
        $defaultMfr = $this->entityTypeManager->getStorage('manufacturer')->load(reset($mfrIds));
      }
    }
    $form['field_manufacturer'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'manufacturer',
      '#title' => $this->t('Manufacturer'),
      '#default_value' => $defaultMfr,
      '#description' => $rowMfrName !== '' && !$defaultMfr
        ? $this->t('Row says "@n" but no manufacturer entity exists with that title. Leave blank and create one separately if needed.', ['@n' => $rowMfrName])
        : $this->t('Optional. Setting this enables Tier 1 manufacturer matches on future ingests.'),
    ];

    $form['field_unit_of_measure'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Unit of measure'),
      '#default_value' => (string) ($this->row->get('field_cost_uom')->value ?? ''),
      '#description' => $this->t('UOM machine name (e.g., EA, LF, M). Some material bundles store this as a select list — the value will round-trip through allowed_values validation on save.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Material and Link'),
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
    $bundle = (string) $form_state->getValue('bundle');
    if ($bundle === '' || !isset($this->bundleOptions[$bundle])) {
      $form_state->setErrorByName('bundle', $this->t('Pick a valid material bundle.'));
    }
    $title = trim((string) $form_state->getValue('title'));
    if ($title === '') {
      $form_state->setErrorByName('title', $this->t('Material title is required.'));
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

    $bundle = (string) $form_state->getValue('bundle');
    $title  = trim((string) $form_state->getValue('title'));
    $mfrItemNum = trim((string) $form_state->getValue('field_manufacturer_item_number'));
    $mfrId = (int) $form_state->getValue('field_manufacturer');
    $uom   = trim((string) $form_state->getValue('field_unit_of_measure'));

    $tx = $this->database->startTransaction();
    try {
      // Build values; only set fields that exist on the chosen bundle.
      $values = ['type' => $bundle, 'uid' => $this->currentUser->id(), 'title' => $title];
      // Pre-populate field_name for AEL-managed bundles so AEL has a
      // sane string to interpolate. (Material bundles with AEL —
      // irrigation, decorative_rock, weeds — use field_size+field_name.)
      $values['field_name'] = $title;
      if ($mfrItemNum !== '') {
        $values['field_manufacturer_item_number'] = $mfrItemNum;
      }
      if ($mfrId > 0) {
        $values['field_manufacturer'] = $mfrId;
      }
      if ($uom !== '') {
        $values['field_unit_of_measure'] = $uom;
      }
      // Pre-populate description from the row so the material's own
      // description field carries the supplier's product copy.
      $values['field_description'] = (string) ($row->get('field_description')->value ?? '');

      $material = $this->entityTypeManager->getStorage('material')->create($values);
      $material->save();
      $materialId = (int) $material->id();

      // Reload after save so AEL-generated label is current (relevant
      // for the success message and the row's notes).
      $material = $this->entityTypeManager->getStorage('material')->load($materialId);

      // Hand off to PriceSyncService — same mutation authority feed
      // committer uses. Creates material_suppliers link + history entry.
      $outcome = $this->priceSync->ingestRow(
        $material,
        $supplier,
        $this->buildRowData($row),
        'feed_import_reviewed',
        (int) $batch->id(),
      );
      if ($outcome->status === 'error') {
        throw new \RuntimeException($outcome->message);
      }

      $row->set('field_row_status', 'discovery_resolved');
      $row->set('field_resolution_action', 'created_new_material_and_link');
      $row->set('field_matched_material', $materialId);
      $row->set('field_resolved_by', $this->currentUser->id());
      $row->set('field_resolved_on', gmdate('Y-m-d\TH:i:s'));
      $this->appendNote(
        $row,
        sprintf('Created new material #%d (%s) [bundle: %s] by %s. PriceSync outcome: %s.',
          $materialId, $material->label(), $bundle, $this->currentUser->getDisplayName(), $outcome->message),
      );
      $row->save();

      $this->messenger()->addStatus($this->t(
        'Created material @label (#@id) and linked to supplier @sup.',
        ['@label' => $material->label(), '@id' => $materialId, '@sup' => $supplier->label()],
      ));
      $form_state->setRedirectUrl(Url::fromUserInput('/admin/materials/supplier-ingest/discovery'));
    }
    catch (\Throwable $e) {
      $tx->rollBack();
      \Drupal::logger('supplier_price_ingest')->error(
        'Create-material-from-row failed for row @rid: @cls @msg',
        ['@rid' => $row->id(), '@cls' => get_class($e), '@msg' => $e->getMessage()],
      );
      $this->messenger()->addError($this->t('Create failed: @msg. No material or link was created.', ['@msg' => $e->getMessage()]));
      $form_state->setRebuild(TRUE);
    }
  }

}
