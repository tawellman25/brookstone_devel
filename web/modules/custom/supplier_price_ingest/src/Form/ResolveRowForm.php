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
 * Unified row-resolution form (Phase 3.7.5 UX iteration 2 — 2026-05-31).
 *
 * Route: /admin/materials/supplier-ingest/discovery/{row}/resolve
 *
 * Replaces the four separate per-operation forms (Create / Link / Mark
 * Replacement / Reject) with one unified form. Reviewer lands on this
 * form, picks an operation via radios, fills in that operation's
 * fields, submits. If they realize mid-edit that a different operation
 * is needed, they pick another radio — context (row summary, batch,
 * SKU) is preserved, only the operation-specific field section swaps.
 *
 * Motivation: in real Discovery Queue work the office reviewer often
 * thinks "I'll link this one" then opens it and realizes the material
 * doesn't exist yet → previously had to click Cancel and go back to
 * the queue to pick a different operation. With this form, just flip
 * the radio.
 *
 * The four legacy single-operation forms (CreateMaterialFromRowForm,
 * LinkRowToMaterialForm, MarkRowAsReplacementForm, RejectRowForm)
 * stay in place as DEEP-LINK fallback for anyone with bookmarks — but
 * the Discovery Queue / Fuzzy Match Review views point at THIS form
 * via a single "Resolve →" operation link.
 *
 * Save-and-load-next: same pattern as the legacy forms — successful
 * submit redirects to the next pending row's Resolve form (via
 * IngestRowFormTrait::nextRowRedirect). Cross-batch fallback + queue
 * fallback still apply.
 *
 * Fuzzy-review rows (field_match_tier = tier_3_fuzzy_med or
 * tier_1_5_title_substring) get a different operation set (Confirm /
 * Override / Send to Discovery / Reject) — same shape, different
 * options. Determined from the row's tier at buildForm time.
 */
class ResolveRowForm extends FormBase {

  use IngestRowFormTrait;

  /** Operation values for discovery-context rows. */
  private const OP_CREATE   = 'create';
  private const OP_LINK     = 'link';
  private const OP_REPLACE  = 'mark_replacement';
  private const OP_REJECT   = 'reject';

  /** Operation values for fuzzy-review-context rows. */
  private const OP_CONFIRM  = 'confirm';
  private const OP_OVERRIDE = 'override';
  private const OP_SEND_TO_DISCOVERY = 'send_to_discovery';
  // OP_REJECT is shared with discovery context.

  private ?EntityInterface $row = NULL;
  private array $bundleOptions = [];
  private string $context = self::CTX_DISCOVERY;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PriceSyncService $priceSync,
    protected IngestMatcher $matcher,
    protected AccountInterface $currentUser,
    protected Connection $database,
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
    return 'supplier_price_ingest_resolve_row';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $supplier_price_ingest_row = NULL): array {
    $this->row = $supplier_price_ingest_row;
    if (!$this->row) {
      $form['error'] = ['#markup' => $this->t('No row loaded.')];
      return $form;
    }

    // Determine context from the row's match tier. Tier 1.5 +
    // tier_3_fuzzy_med both route through Fuzzy Match Review; anything
    // else (typically 'discovery') uses the discovery operation set.
    $tier = (string) ($this->row->get('field_match_tier')->value ?? '');
    $this->context = in_array($tier, ['tier_3_fuzzy_med', 'tier_1_5_title_substring'], TRUE)
      ? self::CTX_FUZZY_REVIEW
      : self::CTX_DISCOVERY;

    $form['back_link'] = $this->buildBackToQueueLink($this->context);
    $this->attachRowFormLibrary($form);

    $form['row_summary'] = $this->buildRowSummary($this->row);

    // ── Operation selector ─────────────────────────────────────────
    $form['operation'] = [
      '#type' => 'radios',
      '#title' => $this->t('What do you want to do with this row?'),
      '#options' => $this->getOperationOptions(),
      '#required' => TRUE,
      '#default_value' => $this->getDefaultOperation(),
      '#attributes' => ['class' => ['bos-resolve-op-radios']],
    ];

    // ── Per-operation field groups (only visible when its op selected) ──
    if ($this->context === self::CTX_DISCOVERY) {
      $this->buildCreateSection($form);
      $this->buildLinkSection($form);
      $this->buildReplaceSection($form);
      $this->buildRejectSection($form, self::OP_REJECT);
    }
    else {
      $this->buildConfirmSection($form);
      $this->buildOverrideSection($form);
      $this->buildSendToDiscoverySection($form);
      $this->buildRejectSection($form, self::OP_REJECT);
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and load next row'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Pre-select an operation based on the row's prior state — if the
   * matcher already proposed a fuzzy candidate, default to Confirm;
   * otherwise leave radios unselected so the reviewer makes a deliberate
   * pick.
   */
  private function getDefaultOperation(): ?string {
    if ($this->context !== self::CTX_FUZZY_REVIEW) {
      return NULL;
    }
    // For fuzzy rows the matcher already proposed a candidate — most
    // common action is Confirm. Pre-select it so a tap on Submit
    // commits the proposed match.
    return $this->row->get('field_matched_material')->isEmpty()
      ? NULL
      : self::OP_CONFIRM;
  }

  private function getOperationOptions(): array {
    if ($this->context === self::CTX_FUZZY_REVIEW) {
      return [
        self::OP_CONFIRM => $this->t('Confirm proposed match'),
        self::OP_OVERRIDE => $this->t('Override — link to a different material'),
        self::OP_SEND_TO_DISCOVERY => $this->t('Send to Discovery Queue'),
        self::OP_REJECT => $this->t('Reject row'),
      ];
    }
    return [
      self::OP_CREATE  => $this->t('Create New Material'),
      self::OP_LINK    => $this->t('Link to Existing Material'),
      self::OP_REPLACE => $this->t('Mark as Replacement (for a discontinued material)'),
      self::OP_REJECT  => $this->t('Reject row'),
    ];
  }

  // ──────────────────────────────────────────────────────────────────
  // DISCOVERY OPERATION SECTIONS
  // ──────────────────────────────────────────────────────────────────

  private function buildCreateSection(array &$form): void {
    $bundles = $this->getBundleOptions();
    $description = (string) ($this->row->get('field_description')->value ?? '');
    $inferred = $this->matcher->inferCandidateBundles($description);

    $rowMfrName = trim((string) ($this->row->get('field_manufacturer_name')->value ?? ''));
    $defaultMfr = NULL;
    if ($rowMfrName !== '') {
      $mfrIds = $this->entityTypeManager->getStorage('manufacturer')->getQuery()
        ->accessCheck(FALSE)->condition('title', $rowMfrName, '=')
        ->sort('id', 'ASC')->range(0, 1)->execute();
      if ($mfrIds) {
        $defaultMfr = $this->entityTypeManager->getStorage('manufacturer')->load(reset($mfrIds));
      }
    }

    $form['create_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Create New Material'),
      '#attributes' => ['class' => ['bos-resolve-section', 'bos-resolve-section-create']],
      '#states' => ['visible' => [':input[name="operation"]' => ['value' => self::OP_CREATE]]],
    ];
    $form['create_section']['create_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Material bundle'),
      '#options' => $bundles,
      '#default_value' => $inferred[0] ?? '',
      '#empty_option' => $this->t('- Select -'),
      '#description' => $inferred
        ? $this->t('Phase 3.4 inferred: <code>@list</code> — first one pre-selected.', ['@list' => implode(', ', $inferred)])
        : $this->t('No bundle inferred from description; pick one.'),
    ];
    $form['create_section']['create_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Material title'),
      '#default_value' => $description !== '' ? $description : '',
      '#maxlength' => 255,
      '#description' => $this->t('AEL-managed bundles (irrigation, decorative_rock, weeds) will override this title from field_size + field_name on save.'),
    ];
    $form['create_section']['create_mfr_item'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Manufacturer item #'),
      '#default_value' => (string) ($this->row->get('field_manufacturer_item_number')->value ?? ''),
      '#description' => $this->t('Drives future Tier 1 matches — leave populated whenever the row has it.'),
    ];
    $form['create_section']['create_mfr'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'manufacturer',
      '#title' => $this->t('Manufacturer'),
      '#default_value' => $defaultMfr,
      '#description' => $rowMfrName !== '' && !$defaultMfr
        ? $this->t('Row says "@n" but no manufacturer entity exists with that title.', ['@n' => $rowMfrName])
        : $this->t('Optional. Setting this enables Tier 1 manufacturer matches on future ingests.'),
    ];
    $form['create_section']['create_uom'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Unit of measure'),
      '#default_value' => (string) ($this->row->get('field_cost_uom')->value ?? ''),
      '#description' => $this->t('UOM machine name (e.g., EA, LF, M).'),
    ];
  }

  private function buildLinkSection(array &$form): void {
    $form['link_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Link to Existing Material'),
      '#attributes' => ['class' => ['bos-resolve-section', 'bos-resolve-section-link']],
      '#states' => ['visible' => [':input[name="operation"]' => ['value' => self::OP_LINK]]],
    ];
    $form['link_section']['link_material'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'material',
      '#title' => $this->t('Existing material to link this row to'),
      '#description' => $this->t('Type to search BOS materials. Discontinued materials are blocked at submit — pick a current SKU.'),
    ];
  }

  private function buildReplaceSection(array &$form): void {
    $form['replace_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Mark as Replacement'),
      '#description' => $this->t('Use this when the row IS the modern replacement for a discontinued BOS material — sets field_replaced_by on the discontinued one and creates the supplier link on the replacement.'),
      '#attributes' => ['class' => ['bos-resolve-section', 'bos-resolve-section-replace']],
      '#states' => ['visible' => [':input[name="operation"]' => ['value' => self::OP_REPLACE]]],
    ];
    $form['replace_section']['replace_discontinued'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'material',
      '#title' => $this->t('Discontinued material this row replaces'),
      '#description' => $this->t('Only materials with field_discontinued = TRUE are valid choices.'),
    ];
    $form['replace_section']['replace_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Replacement material source'),
      '#options' => [
        'existing' => $this->t('Use an existing material'),
        'new'      => $this->t('Create a new material from this row'),
      ],
      '#default_value' => 'existing',
    ];
    $form['replace_section']['replace_existing'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'material',
      '#title' => $this->t('Replacement material (existing)'),
      '#description' => $this->t('Pick the current material that supersedes the discontinued one. Cannot itself be discontinued.'),
      '#states' => ['visible' => [':input[name="replace_mode"]' => ['value' => 'existing']]],
    ];
    $form['replace_section']['replace_new_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('New replacement: bundle'),
      '#options' => $this->getBundleOptions(),
      '#default_value' => '',
      '#empty_option' => $this->t('- Select -'),
      '#states' => ['visible' => [':input[name="replace_mode"]' => ['value' => 'new']]],
    ];
    $form['replace_section']['replace_new_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New replacement: title'),
      '#default_value' => (string) ($this->row->get('field_description')->value ?? ''),
      '#maxlength' => 255,
      '#states' => ['visible' => [':input[name="replace_mode"]' => ['value' => 'new']]],
    ];
  }

  // ──────────────────────────────────────────────────────────────────
  // FUZZY-REVIEW OPERATION SECTIONS
  // ──────────────────────────────────────────────────────────────────

  private function buildConfirmSection(array &$form): void {
    $matched = $this->row->get('field_matched_material')->entity;
    $form['confirm_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Confirm Proposed Match'),
      '#attributes' => ['class' => ['bos-resolve-section', 'bos-resolve-section-confirm']],
      '#states' => ['visible' => [':input[name="operation"]' => ['value' => self::OP_CONFIRM]]],
    ];
    if ($matched) {
      $form['confirm_section']['proposed'] = [
        '#type' => 'item',
        '#title' => $this->t('Proposed match'),
        '#markup' => $this->t(
          '<strong>#@id — @label</strong> (bundle: @b) — confidence @c',
          [
            '@id' => $matched->id(),
            '@label' => $matched->label(),
            '@b' => $matched->bundle(),
            '@c' => (string) ($this->row->get('field_match_confidence')->value ?? '—'),
          ],
        ),
      ];
    }
    else {
      $form['confirm_section']['no_match'] = [
        '#type' => 'item',
        '#markup' => $this->t('No proposed material on this row — pick Override or Send to Discovery instead.'),
      ];
    }
  }

  private function buildOverrideSection(array &$form): void {
    $form['override_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Override Match'),
      '#description' => $this->t('Replace the matcher\'s proposed material with a different one.'),
      '#attributes' => ['class' => ['bos-resolve-section', 'bos-resolve-section-override']],
      '#states' => ['visible' => [':input[name="operation"]' => ['value' => self::OP_OVERRIDE]]],
    ];
    $form['override_section']['override_material'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'material',
      '#title' => $this->t('Correct material'),
      '#description' => $this->t('Type to search. Discontinued materials are blocked at submit.'),
    ];
  }

  private function buildSendToDiscoverySection(array &$form): void {
    $form['send_to_discovery_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Send to Discovery Queue'),
      '#description' => $this->t('Use when the proposed fuzzy match is wrong AND the correct material does not exist in BOS yet. The Discovery reviewer can then create a new material from this row.'),
      '#attributes' => ['class' => ['bos-resolve-section', 'bos-resolve-section-send-disc']],
      '#states' => ['visible' => [':input[name="operation"]' => ['value' => self::OP_SEND_TO_DISCOVERY]]],
    ];
  }

  // ──────────────────────────────────────────────────────────────────
  // REJECT (shared)
  // ──────────────────────────────────────────────────────────────────

  private function buildRejectSection(array &$form, string $opValue): void {
    $form['reject_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Reject row'),
      '#attributes' => ['class' => ['bos-resolve-section', 'bos-resolve-section-reject']],
      '#states' => ['visible' => [':input[name="operation"]' => ['value' => $opValue]]],
    ];
    $form['reject_section']['reject_notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Rejection notes (optional)'),
      '#rows' => 3,
      '#description' => $this->t('Captured in the row\'s resolution notes for audit.'),
    ];
  }

  private function getBundleOptions(): array {
    if (!empty($this->bundleOptions)) {
      return $this->bundleOptions;
    }
    $bundleInfo = \Drupal::service('entity_type.bundle.info')->getBundleInfo('material');
    $bundles = [];
    foreach ($bundleInfo as $machine => $info) {
      $bundles[$machine] = $info['label'] . " ({$machine})";
    }
    ksort($bundles);
    return $this->bundleOptions = $bundles;
  }

  // ──────────────────────────────────────────────────────────────────
  // VALIDATION
  // ──────────────────────────────────────────────────────────────────

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $op = (string) $form_state->getValue('operation');
    switch ($op) {
      case self::OP_CREATE:
        if (($form_state->getValue('create_bundle') ?? '') === '') {
          $form_state->setErrorByName('create_bundle', $this->t('Pick a material bundle.'));
        }
        if (trim((string) $form_state->getValue('create_title')) === '') {
          $form_state->setErrorByName('create_title', $this->t('Material title is required.'));
        }
        break;

      case self::OP_LINK:
        $matId = (int) $form_state->getValue('link_material');
        if ($matId <= 0) {
          $form_state->setErrorByName('link_material', $this->t('Pick a material.'));
          return;
        }
        $material = $this->entityTypeManager->getStorage('material')->load($matId);
        if (!$material) {
          $form_state->setErrorByName('link_material', $this->t('Material does not exist.'));
          return;
        }
        if ($material->hasField('field_discontinued') && (bool) ($material->get('field_discontinued')->value ?? FALSE)) {
          $form_state->setErrorByName(
            'link_material',
            $this->t('Material "@l" is discontinued. Pick a current material — or switch to Mark as Replacement if this row IS the replacement.', ['@l' => $material->label()]),
          );
        }
        break;

      case self::OP_REPLACE:
        $discId = (int) $form_state->getValue('replace_discontinued');
        if ($discId <= 0) {
          $form_state->setErrorByName('replace_discontinued', $this->t('Pick the discontinued material this row replaces.'));
          return;
        }
        $disc = $this->entityTypeManager->getStorage('material')->load($discId);
        if (!$disc) {
          $form_state->setErrorByName('replace_discontinued', $this->t('Discontinued material no longer exists.'));
          return;
        }
        if (!$disc->hasField('field_discontinued') || !(bool) ($disc->get('field_discontinued')->value ?? FALSE)) {
          $form_state->setErrorByName(
            'replace_discontinued',
            $this->t('Material "@l" is not flagged as discontinued.', ['@l' => $disc->label()]),
          );
          return;
        }
        $mode = (string) $form_state->getValue('replace_mode');
        if ($mode === 'existing') {
          $repId = (int) $form_state->getValue('replace_existing');
          if ($repId <= 0) {
            $form_state->setErrorByName('replace_existing', $this->t('Pick the existing replacement material.'));
            return;
          }
          if ($repId === $discId) {
            $form_state->setErrorByName('replace_existing', $this->t('Replacement cannot be the same as the discontinued material.'));
            return;
          }
          $rep = $this->entityTypeManager->getStorage('material')->load($repId);
          if (!$rep) {
            $form_state->setErrorByName('replace_existing', $this->t('Replacement material does not exist.'));
          }
          elseif ($rep->hasField('field_discontinued') && (bool) ($rep->get('field_discontinued')->value ?? FALSE)) {
            $form_state->setErrorByName('replace_existing', $this->t('Replacement "@l" is itself discontinued.', ['@l' => $rep->label()]));
          }
        }
        elseif ($mode === 'new') {
          if (($form_state->getValue('replace_new_bundle') ?? '') === '') {
            $form_state->setErrorByName('replace_new_bundle', $this->t('Pick a bundle for the new replacement material.'));
          }
          if (trim((string) $form_state->getValue('replace_new_title')) === '') {
            $form_state->setErrorByName('replace_new_title', $this->t('Title is required for the new replacement material.'));
          }
        }
        break;

      case self::OP_CONFIRM:
        if ($this->row->get('field_matched_material')->isEmpty()) {
          $form_state->setErrorByName('operation', $this->t('Row has no proposed match to confirm — pick Override or Send to Discovery.'));
        }
        break;

      case self::OP_OVERRIDE:
        $matId = (int) $form_state->getValue('override_material');
        if ($matId <= 0) {
          $form_state->setErrorByName('override_material', $this->t('Pick a material to override with.'));
          return;
        }
        $material = $this->entityTypeManager->getStorage('material')->load($matId);
        if (!$material) {
          $form_state->setErrorByName('override_material', $this->t('Material does not exist.'));
          return;
        }
        if ($material->hasField('field_discontinued') && (bool) ($material->get('field_discontinued')->value ?? FALSE)) {
          $form_state->setErrorByName('override_material', $this->t('Material "@l" is discontinued.', ['@l' => $material->label()]));
        }
        break;

      // OP_REJECT + OP_SEND_TO_DISCOVERY — no required fields.
    }
  }

  // ──────────────────────────────────────────────────────────────────
  // SUBMIT — dispatches based on selected operation
  // ──────────────────────────────────────────────────────────────────

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $row = $this->row;
    if (!$row) {
      return;
    }
    // Defensive: clear destination= from the request so the next-row
    // redirect actually fires. Drupal's redirect logic honors
    // ?destination= over our setRedirectUrl(); the Operations column
    // on the view used to add it.
    \Drupal::request()->query->remove('destination');

    $op = (string) $form_state->getValue('operation');
    try {
      switch ($op) {
        case self::OP_CREATE:           $this->submitCreate($form_state); break;
        case self::OP_LINK:             $this->submitLink($form_state); break;
        case self::OP_REPLACE:          $this->submitReplace($form_state); break;
        case self::OP_REJECT:           $this->submitReject($form_state); break;
        case self::OP_CONFIRM:          $this->submitConfirm($form_state); break;
        case self::OP_OVERRIDE:         $this->submitOverride($form_state); break;
        case self::OP_SEND_TO_DISCOVERY:$this->submitSendToDiscovery($form_state); break;
        default:
          $this->messenger()->addError($this->t('Unknown operation.'));
          $form_state->setRebuild(TRUE);
          return;
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('supplier_price_ingest')->error(
        'ResolveRowForm @op failed for row @rid: @cls @msg',
        ['@op' => $op, '@rid' => $row->id(), '@cls' => get_class($e), '@msg' => $e->getMessage()],
      );
      $this->messenger()->addError($this->t('@op failed: @msg. No changes were saved.', ['@op' => $op, '@msg' => $e->getMessage()]));
      $form_state->setRebuild(TRUE);
      return;
    }

    $form_state->setRedirectUrl($this->nextRowRedirect(
      $row,
      'supplier_price_ingest.resolve_row',
      $this->context,
      $this->entityTypeManager,
      $this->messenger(),
    ));
  }

  // ── Per-operation submit handlers ─────────────────────────────────

  private function submitCreate(FormStateInterface $form_state): void {
    $row = $this->row;
    $batch = $row->get('field_batch')->entity;
    $supplier = $batch ? $batch->get('field_supplier')->entity : NULL;
    if (!$supplier) {
      throw new \RuntimeException('Row has no resolvable supplier.');
    }
    $bundle = (string) $form_state->getValue('create_bundle');
    $title  = trim((string) $form_state->getValue('create_title'));
    $mfrItemNum = trim((string) $form_state->getValue('create_mfr_item'));
    $mfrId = (int) $form_state->getValue('create_mfr');
    $uom   = trim((string) $form_state->getValue('create_uom'));

    $tx = $this->database->startTransaction();
    try {
      $values = [
        'type' => $bundle, 'uid' => $this->currentUser->id(), 'title' => $title,
        'field_name' => $title,
        'field_description' => (string) ($row->get('field_description')->value ?? ''),
      ];
      if ($mfrItemNum !== '') { $values['field_manufacturer_item_number'] = $mfrItemNum; }
      if ($mfrId > 0)         { $values['field_manufacturer'] = $mfrId; }
      if ($uom !== '')        { $values['field_unit_of_measure'] = $uom; }
      $material = $this->entityTypeManager->getStorage('material')->create($values);
      $material->save();
      $materialId = (int) $material->id();
      $material = $this->entityTypeManager->getStorage('material')->load($materialId);

      $outcome = $this->priceSync->ingestRow(
        $material, $supplier, $this->buildRowData($row),
        'feed_import_reviewed', (int) $batch->id(),
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
        sprintf('Created new material #%d (%s) [bundle: %s] via Resolve form by %s. PriceSync outcome: %s.',
          $materialId, $material->label(), $bundle, $this->currentUser->getDisplayName(), $outcome->message),
      );
      $row->save();

      $this->messenger()->addStatus($this->t(
        'Created material @label (#@id) and linked to supplier @sup.',
        ['@label' => $material->label(), '@id' => $materialId, '@sup' => $supplier->label()],
      ));
    }
    catch (\Throwable $e) {
      $tx->rollBack();
      throw $e;
    }
  }

  private function submitLink(FormStateInterface $form_state): void {
    $row = $this->row;
    $matId = (int) $form_state->getValue('link_material');
    $material = $this->entityTypeManager->getStorage('material')->load($matId);
    $batch = $row->get('field_batch')->entity;
    $supplier = $batch ? $batch->get('field_supplier')->entity : NULL;
    if (!$material || !$supplier) {
      throw new \RuntimeException('Missing material or supplier.');
    }
    $outcome = $this->priceSync->ingestRow(
      $material, $supplier, $this->buildRowData($row),
      'feed_import_reviewed', (int) $batch->id(),
    );
    if ($outcome->status === 'error') {
      throw new \RuntimeException($outcome->message);
    }
    $row->set('field_row_status', 'discovery_resolved');
    $row->set('field_resolution_action', 'linked_to_existing_material');
    $row->set('field_matched_material', $material->id());
    $row->set('field_resolved_by', $this->currentUser->id());
    $row->set('field_resolved_on', gmdate('Y-m-d\TH:i:s'));
    $this->appendNote(
      $row,
      sprintf('Linked to existing material #%d (%s) via Resolve form by %s. PriceSync outcome: %s.',
        $material->id(), $material->label(), $this->currentUser->getDisplayName(), $outcome->message),
    );
    $row->save();
    $this->messenger()->addStatus($this->t('Row linked to @label.', ['@label' => $material->label()]));
  }

  private function submitReplace(FormStateInterface $form_state): void {
    $row = $this->row;
    $batch = $row->get('field_batch')->entity;
    $supplier = $batch ? $batch->get('field_supplier')->entity : NULL;
    if (!$supplier) {
      throw new \RuntimeException('Row has no resolvable supplier.');
    }
    $discId = (int) $form_state->getValue('replace_discontinued');
    $disc = $this->entityTypeManager->getStorage('material')->load($discId);
    $mode = (string) $form_state->getValue('replace_mode');

    $tx = $this->database->startTransaction();
    try {
      if ($mode === 'existing') {
        $rep = $this->entityTypeManager->getStorage('material')->load((int) $form_state->getValue('replace_existing'));
      }
      else {
        $bundle = (string) $form_state->getValue('replace_new_bundle');
        $title  = trim((string) $form_state->getValue('replace_new_title'));
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

      if (!$disc->hasField('field_replaced_by')) {
        throw new \RuntimeException('Discontinued material lacks field_replaced_by.');
      }
      $disc->set('field_replaced_by', $rep->id());
      $disc->save();

      $outcome = $this->priceSync->ingestRow(
        $rep, $supplier, $this->buildRowData($row),
        'feed_import_reviewed', (int) $batch->id(),
      );
      if ($outcome->status === 'error') {
        throw new \RuntimeException($outcome->message);
      }

      $row->set('field_row_status', 'discovery_resolved');
      $row->set('field_resolution_action', 'marked_as_replacement');
      $row->set('field_matched_material', $rep->id());
      $row->set('field_resolved_by', $this->currentUser->id());
      $row->set('field_resolved_on', gmdate('Y-m-d\TH:i:s'));
      $this->appendNote(
        $row,
        sprintf("Marked as replacement for discontinued material #%d ('%s'). Replacement: #%d (%s) [%s] via Resolve form. By %s.",
          $disc->id(), $disc->label(), $rep->id(), $rep->label(),
          $mode === 'new' ? 'created from this row' : 'existing material',
          $this->currentUser->getDisplayName()),
      );
      $row->save();

      $this->messenger()->addStatus($this->t(
        'Marked as replacement: discontinued #@d → current #@r.',
        ['@d' => $disc->id(), '@r' => $rep->id()],
      ));
    }
    catch (\Throwable $e) {
      $tx->rollBack();
      throw $e;
    }
  }

  private function submitReject(FormStateInterface $form_state): void {
    $row = $this->row;
    $notes = trim((string) $form_state->getValue('reject_notes'));
    $row->set('field_row_status', 'rejected');
    $row->set('field_resolution_action', 'rejected');
    $row->set('field_resolved_by', $this->currentUser->id());
    $row->set('field_resolved_on', gmdate('Y-m-d\TH:i:s'));
    $rejectLine = sprintf('Rejected via Resolve form by %s on %s.',
      $this->currentUser->getDisplayName(), date('m/d/Y g:i A'));
    if ($notes !== '') {
      $rejectLine .= ' Reason: ' . $notes;
    }
    $this->appendNote($row, $rejectLine);
    $row->save();
    $this->messenger()->addStatus($this->t('Row #@n rejected.', ['@n' => $row->id()]));
  }

  private function submitConfirm(FormStateInterface $form_state): void {
    $row = $this->row;
    $matched = $row->get('field_matched_material')->entity;
    $batch = $row->get('field_batch')->entity;
    $supplier = $batch ? $batch->get('field_supplier')->entity : NULL;
    if (!$matched || !$supplier) {
      throw new \RuntimeException('Missing matched material or supplier.');
    }
    $outcome = $this->priceSync->ingestRow(
      $matched, $supplier, $this->buildRowData($row),
      'feed_import_reviewed', (int) $batch->id(),
    );
    if ($outcome->status === 'error') {
      throw new \RuntimeException($outcome->message);
    }
    $row->set('field_row_status', 'committed');
    $row->set('field_resolution_action', 'confirmed_match');
    $row->set('field_resolved_by', $this->currentUser->id());
    $row->set('field_resolved_on', gmdate('Y-m-d\TH:i:s'));
    $this->appendNote(
      $row,
      sprintf('Fuzzy match confirmed → material #%d (%s) via Resolve form by %s. PriceSync outcome: %s.',
        $matched->id(), $matched->label(), $this->currentUser->getDisplayName(), $outcome->message),
    );
    $row->save();
    $this->messenger()->addStatus($this->t('Confirmed match → @l.', ['@l' => $matched->label()]));
  }

  private function submitOverride(FormStateInterface $form_state): void {
    $row = $this->row;
    $matId = (int) $form_state->getValue('override_material');
    $material = $this->entityTypeManager->getStorage('material')->load($matId);
    $batch = $row->get('field_batch')->entity;
    $supplier = $batch ? $batch->get('field_supplier')->entity : NULL;
    if (!$material || !$supplier) {
      throw new \RuntimeException('Missing material or supplier.');
    }
    $previousMatched = $row->get('field_matched_material')->entity;
    $outcome = $this->priceSync->ingestRow(
      $material, $supplier, $this->buildRowData($row),
      'feed_import_reviewed', (int) $batch->id(),
    );
    if ($outcome->status === 'error') {
      throw new \RuntimeException($outcome->message);
    }
    $row->set('field_row_status', 'committed');
    $row->set('field_resolution_action', 'overridden_match');
    $row->set('field_matched_material', $material->id());
    $row->set('field_resolved_by', $this->currentUser->id());
    $row->set('field_resolved_on', gmdate('Y-m-d\TH:i:s'));
    $this->appendNote(
      $row,
      sprintf("Fuzzy match overridden — was #%s (%s), now #%d (%s). Via Resolve form by %s. PriceSync: %s.",
        $previousMatched ? $previousMatched->id() : '—',
        $previousMatched ? $previousMatched->label() : 'none',
        $material->id(), $material->label(),
        $this->currentUser->getDisplayName(), $outcome->message),
    );
    $row->save();
    $this->messenger()->addStatus($this->t('Override committed → @l.', ['@l' => $material->label()]));
  }

  private function submitSendToDiscovery(FormStateInterface $form_state): void {
    $row = $this->row;
    $row->set('field_match_tier', 'discovery');
    $row->set('field_matched_material', NULL);
    $row->set('field_match_confidence', 0);
    $row->set('field_resolved_by', $this->currentUser->id());
    $row->set('field_resolved_on', gmdate('Y-m-d\TH:i:s'));
    $this->appendNote(
      $row,
      sprintf('Sent from Fuzzy Match Review to Discovery Queue via Resolve form by %s on %s.',
        $this->currentUser->getDisplayName(), date('m/d/Y g:i A')),
    );
    $row->save();
    $this->messenger()->addStatus($this->t('Row sent to Discovery Queue.'));
  }

}
