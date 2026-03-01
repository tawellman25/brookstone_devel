<?php

namespace Drupal\contract_residential\Service;

use Drupal\contract_residential\WorkOrderEnrichers\EnricherManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\taxonomy\TermInterface;

/**
 * BOS engine: Create Work Orders from Contract Sections using Services mapping.
 *
 * Authoritative rules for WO generation:
 * - Actor must be authenticated (never Anonymous).
 * - Only create WOs when Section intent is explicitly YES (field_do_you_want).
 * - Never overwrite existing WO values.
 * - Never overwrite existing section->work_order pointer fields.
 * - Enrichers are additive-only.
 * - No per-service branching in generator.
 * - Allowlist gate (config) can exclude on-demand bundles.
 *
 * Work To Do Description rules (as approved):
 * 1) Year must come from Contract.field_contract_year (immutable per contract year).
 * 2) Fallback description is set only if NO enricher set it.
 * 3) Enrichers may set descriptions per service (additive-only, if empty or default markers).
 */
final class WorkOrderGenerator {

  private const CONTRACT_STATUS_VID = 'contract_status';

  /**
   * Allowed statuses for Work Order creation.
   *
   * 1124 ("Work Orders Created") must remain eligible for future generation when
   * intent changes or new sections are added.
   */
  private const ALLOWED_CONTRACT_STATUS_TIDS = [1123, 1651, 1124];

  private const DEFAULT_WO_STATUS_TID = 1089;

  private const SETTINGS_NAME = 'contract_residential.settings';
  private const SETTINGS_KEY_AUTOGEN_BUNDLES = 'work_order_autogenerate_bundles';

  /**
   * Option 2: Multistage creation is allowed only for allowlisted Contract
   * Section bundle machine names.
   *
   * This prevents accidental 2nd/3rd/4th WOs for section bundles that happen to
   * have extra pointer fields but are not intended to be multistage.
   *
   * Fail-closed: missing/empty => multistage disabled.
   */
  private const SETTINGS_KEY_MULTISTAGE_SECTION_BUNDLES = 'work_order_multistage_section_bundles';

  private const SECTION_SEASON_FIELD_CANDIDATES = [
    'field_fertilizing_season',
    'field_aerating_season',
    'field_pre_emergent_season',
    'field_deer_prevention_season',
    'field_grub_prevention_season',
    'field_dormant_oil_season',
    'field_dethatching_season',
    'field_trunk_bore_season',
    'field_aspen_twig_gall_season',
    'field_cooley_spruce_gall_season',
    'field_deciduous_bore_season',
    'field_pinion_pine_ips_beetle_season',
    'field_christmas_decorations_season',
  ];

  private const SEASON_TOKENS = [
    'early_spring' => 'Early Spring',
    'mid_spring' => 'Mid Spring',
    'late_spring' => 'Late Spring',
    'spring' => 'Spring',
    'early_summer' => 'Early Summer',
    'mid_summer' => 'Mid Summer',
    'late_summer' => 'Late Summer',
    'summer' => 'Summer',
    'early_fall' => 'Early Fall',
    'mid_fall' => 'Mid Fall',
    'late_fall' => 'Late Fall',
    'fall' => 'Fall',
    'autumn' => 'Fall',
    'winter' => 'Winter',
  ];

  private EntityTypeManagerInterface $etm;
  private EntityTypeBundleInfoInterface $bundleInfo;
  private LoggerChannelFactoryInterface $loggerFactory;
  private ConfigFactoryInterface $configFactory;
  private EnricherManager $enricherManager;
  private AccountProxyInterface $currentUser;

  /** @var array<string,int|null> */
  private array $statusTidByNameCache = [];

  /** @var string[]|null */
  private ?array $autoGenerateBundles = NULL;

  /** @var string[]|null */
  private ?array $multiStageSectionBundles = NULL;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $bundle_info,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    EnricherManager $enricher_manager,
    AccountProxyInterface $current_user
  ) {
    $this->etm = $entity_type_manager;
    $this->bundleInfo = $bundle_info;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
    $this->enricherManager = $enricher_manager;
    $this->currentUser = $current_user;
  }

  public function generateFromContract(int $contract_id, array $options = []): WorkOrderGenerationResult {
    $dry_run = !empty($options['dry_run']);
    $result = new WorkOrderGenerationResult($dry_run);

    /** @var \Drupal\Core\Entity\EntityInterface|null $contract */
    $contract = $this->etm->getStorage('contracts')->load($contract_id);
    if (!$contract) {
      $result->addMessage("ERROR: Contract {$contract_id} not found.");
      return $result;
    }

    if (!$contract->hasField('field_contract_status') || $contract->get('field_contract_status')->isEmpty()) {
      $result->addMessage("ERROR: Contract {$contract_id} missing field_contract_status.");
      return $result;
    }

    $contract_status_tid = (int) $contract->get('field_contract_status')->target_id;
    if (!in_array($contract_status_tid, self::ALLOWED_CONTRACT_STATUS_TIDS, TRUE)) {
      $result->addMessage("ERROR: Contract {$contract_id} status {$contract_status_tid} does not allow Work Order creation.");
      return $result;
    }

    $section_storage = $this->etm->getStorage('contract_sections');
    $ids = $section_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_contract.target_id', $contract_id)
      ->exists('field_service')
      ->execute();

    if (!$ids) {
      $result->addMessage("Contract {$contract_id}: no Contract Sections found for generation.");
      return $result;
    }

    $sections = $section_storage->loadMultiple($ids);
    foreach ($sections as $section) {
      if (!$section instanceof EntityInterface) {
        $result->addSkipped();
        continue;
      }
      $this->processSection($section, $result, $options);
    }

    return $result;
  }

  public function generateFromSection(EntityInterface $section, array $options = []): WorkOrderGenerationResult {
    $dry_run = !empty($options['dry_run']);
    $result = new WorkOrderGenerationResult($dry_run);
    $this->processSection($section, $result, $options);
    return $result;
  }

  public function resolveContractStatusTidByName(string $name): ?int {
    if (array_key_exists($name, $this->statusTidByNameCache)) {
      return $this->statusTidByNameCache[$name];
    }

    $ids = $this->etm->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::CONTRACT_STATUS_VID)
      ->condition('name', $name)
      ->range(0, 1)
      ->execute();

    $tid = $ids ? (int) reset($ids) : NULL;
    $this->statusTidByNameCache[$name] = $tid;
    return $tid;
  }

  private function processSection(EntityInterface $section, WorkOrderGenerationResult $result, array $options): void {
    $dry_run = $result->isDryRun();
    $sid = (int) $section->id();

    // Hard rule: must be authored by an authenticated actor.
    $actor_uid = (int) $this->currentUser->id();
    if ($actor_uid <= 0) {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: REFUSED: current user is Anonymous; Work Orders must be created by the actor who triggered the action.");
      return;
    }

    if (!$section->hasField('field_contract') || $section->get('field_contract')->isEmpty()) {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: missing required field_contract.");
      return;
    }

    if (!$section->hasField('field_service') || $section->get('field_service')->isEmpty()) {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: missing required field_service.");
      return;
    }

    // Respect client intent.
    if (!$this->sectionWantsWorkOrder($section, $result)) {
      return;
    }

    $contract_id = (int) $section->get('field_contract')->target_id;

    /** @var \Drupal\Core\Entity\EntityInterface|null $contract */
    $contract = $this->etm->getStorage('contracts')->load($contract_id);
    if (!$contract) {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: parent Contract {$contract_id} not found.");
      return;
    }

    if (!$contract->hasField('field_contract_status') || $contract->get('field_contract_status')->isEmpty()) {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: Contract {$contract_id} missing field_contract_status.");
      return;
    }

    $contract_status_tid = (int) $contract->get('field_contract_status')->target_id;
    if (!in_array($contract_status_tid, self::ALLOWED_CONTRACT_STATUS_TIDS, TRUE)) {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: Contract {$contract_id} status {$contract_status_tid} does not allow Work Order creation.");
      return;
    }

    if (!$contract->hasField('field_property') || $contract->get('field_property')->isEmpty()) {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: Contract {$contract_id} missing required field_property.");
      return;
    }
    $property_id = (int) $contract->get('field_property')->target_id;

    $service_tid = (int) $section->get('field_service')->target_id;
    /** @var \Drupal\taxonomy\TermInterface|null $service */
    $service = $this->etm->getStorage('taxonomy_term')->load($service_tid);
    if (!$service instanceof TermInterface) {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: Service term {$service_tid} not found.");
      return;
    }

    if (!$service->hasField('field_work_order_service') || (int) $service->get('field_work_order_service')->value !== 1) {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: Service {$service_tid} is not a Work Order service (field_work_order_service != 1).");
      return;
    }

    if (!$service->hasField('field_service_bundle') || $service->get('field_service_bundle')->isEmpty()) {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: Service {$service_tid} missing field_service_bundle mapping.");
      return;
    }

    $wo_bundle = (string) $service->get('field_service_bundle')->value;
    $wo_bundles = $this->bundleInfo->getBundleInfo('work_order');
    if (!isset($wo_bundles[$wo_bundle])) {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: Service {$service_tid} field_service_bundle '{$wo_bundle}' is not a valid work_order bundle.");
      return;
    }

    // Allowlist gate.
    if (!$this->isAutoGenerationEnabledForBundle($wo_bundle)) {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: work_order bundle '{$wo_bundle}' is not enabled for auto-generation (on-demand workflow).");
      return;
    }

    // Option 2: multistage is allowed only for allowlisted Contract Section bundles.
    $request_multistage = !empty($options['fill_multistage_slots']);
    $fill_multistage = $request_multistage && $this->isMultiStageAllowedForSectionBundle($section->bundle());

    $pointer_field = $this->determinePointerFieldToFill($section, $fill_multistage);
    if ($pointer_field === NULL) {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: no available Work Order pointer field to fill (existing link(s) present).");
      return;
    }

    $estimated_price = $this->extractEstimatedPriceFromSection($section);

    if ($dry_run) {
      $result->addWouldCreate();
      $result->addMessage("Section {$sid}: would create Work Order bundle '{$wo_bundle}' and link via {$pointer_field}.");
      return;
    }

    try {
      $wo_storage = $this->etm->getStorage('work_order');

      $service_label = (string) $service->label();

      $create = [
        'type' => $wo_bundle,
        'uid' => $actor_uid,
        'created' => time(),
        'title' => $service_label . ' Work Order',
        'field_service' => $service_tid,
        'field_contract' => $contract_id,
        'field_property' => $property_id,
        'field_status' => self::DEFAULT_WO_STATUS_TID,
        'field_invoiced' => 0,
      ];

      if ($estimated_price !== NULL && $this->workOrderHasField($wo_bundle, 'field_estimated_price')) {
        $create['field_estimated_price'] = $estimated_price;
      }

      /** @var \Drupal\Core\Entity\EntityInterface $work_order */
      $work_order = $wo_storage->create($create);

      // Enrichers run first (service-specific wins).
      $context = [
        'contract_id' => $contract_id,
        'section_id' => $sid,
        'service_tid' => $service_tid,
        'wo_bundle' => $wo_bundle,
        'pointer_field' => $pointer_field,
      ];
      $this->enricherManager->applyAll($contract, $section, $service, $work_order, $context, $result, $options);

      // Global fallback Work To Do Description (only if enabled AND still empty after enrichers).
      if (!empty($options['set_work_todo_description'])
        && $work_order->hasField('field_work_todo_description')
        && $work_order->get('field_work_todo_description')->isEmpty()
      ) {
        $fallback = $this->buildWorkTodoDescriptionHtml($contract, $service_label);
        if ($fallback !== NULL) {
          $work_order->set('field_work_todo_description', [
            'value' => $fallback,
            'format' => 'full_html',
          ]);
        }
      }

      $work_order->save();

      // Post-save enrichment pass (for enrichers that require a WO id, e.g. scheduling).
      $this->enricherManager->applyAll($contract, $section, $service, $work_order, $context, $result, $options + [
        'enricher_phase' => 'post_save',
      ]);

      $wo_id = (int) $work_order->id();

      $section->set($pointer_field, $wo_id);
      $section->save();

      $result->addCreated();
      $result->addMessage("Section {$sid}: created Work Order {$wo_id} ({$wo_bundle}) and linked via {$pointer_field}.");
    }
    catch (EntityStorageException $e) {
      $result->addSkipped();
      $result->addMessage("ERROR: Section {$sid}: storage error creating/linking Work Order ({$e->getMessage()}).");
      $this->loggerFactory->get('contract_residential')->error($e->getMessage());
    }
    catch (\Throwable $e) {
      $result->addSkipped();
      $result->addMessage("ERROR: Section {$sid}: unexpected error ({$e->getMessage()}).");
      $this->loggerFactory->get('contract_residential')->error($e->getMessage());
    }
  }

  /**
   * Only create WOs when field_do_you_want is explicitly YES.
   *
   * field_do_you_want is List (text) with keys:
   * - 1 = Yes
   * - 2 = No
   * - 3 = Request Quote
   */
  private function sectionWantsWorkOrder(EntityInterface $section, WorkOrderGenerationResult $result): bool {
    $sid = (int) $section->id();

    if (!$section->hasField('field_do_you_want')) {
      // If the field doesn't exist on the bundle, do not block.
      return TRUE;
    }

    if ($section->get('field_do_you_want')->isEmpty()) {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: field_do_you_want is empty; refusing to create Work Order until intent is explicitly set to Yes (1).");
      return FALSE;
    }

    $raw = trim((string) $section->get('field_do_you_want')->value);

    if ($raw === '1') {
      return TRUE;
    }

    if ($raw === '2') {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: field_do_you_want = No (2).");
      return FALSE;
    }

    if ($raw === '3') {
      $result->addSkipped();
      $result->addMessage("Section {$sid}: field_do_you_want = Request Quote (3); refusing to create Work Order.");
      return FALSE;
    }

    $result->addSkipped();
    $result->addMessage("Section {$sid}: field_do_you_want has unexpected raw value '{$raw}'; refusing to create Work Order.");
    return FALSE;
  }

  private function isAutoGenerationEnabledForBundle(string $wo_bundle): bool {
    if ($this->autoGenerateBundles === NULL) {
      $cfg = $this->configFactory->get(self::SETTINGS_NAME);
      $raw = $cfg ? $cfg->get(self::SETTINGS_KEY_AUTOGEN_BUNDLES) : NULL;

      // Fail-closed governance:
      // - If the allowlist is missing/empty => auto-generation is DISABLED.
      // - If the allowlist is the wrong type (e.g. JSON string via drush cset)
      //   => auto-generation is DISABLED.
      $bundles = [];
      if ($raw === NULL) {
        $this->loggerFactory->get('contract_residential')->warning(
          'Auto-generation allowlist is not set (@key). Auto-generation is disabled until explicitly configured.',
          ['@key' => self::SETTINGS_NAME . ':' . self::SETTINGS_KEY_AUTOGEN_BUNDLES]
        );
      }
      elseif (!is_array($raw)) {
        $this->loggerFactory->get('contract_residential')->error(
          'Auto-generation allowlist has invalid type (@type). Expected YAML sequence (array). Auto-generation is disabled.',
          ['@type' => gettype($raw)]
        );
      }
      else {
        foreach ($raw as $v) {
          $v = trim((string) $v);
          if ($v !== '') {
            $bundles[] = $v;
          }
        }
      }

      $bundles = array_values(array_unique($bundles));
      sort($bundles);
      $this->autoGenerateBundles = $bundles;
    }

    // Empty allowlist => allow none (fail closed).
    if ($this->autoGenerateBundles === []) {
      return FALSE;
    }

    return in_array($wo_bundle, $this->autoGenerateBundles, TRUE);
  }

  /**
   * Option 2: multistage is allowed only for allowlisted Contract Section bundles.
   * Fail closed: missing/empty => no multistage.
   */
  private function isMultiStageAllowedForSectionBundle(string $section_bundle): bool {
    if ($this->multiStageSectionBundles === NULL) {
      $cfg = $this->configFactory->get(self::SETTINGS_NAME);
      $raw = $cfg ? $cfg->get(self::SETTINGS_KEY_MULTISTAGE_SECTION_BUNDLES) : NULL;

      $bundles = [];
      if (is_array($raw)) {
        foreach ($raw as $v) {
          $v = trim((string) $v);
          if ($v !== '') {
            $bundles[] = $v;
          }
        }
      }

      $bundles = array_values(array_unique($bundles));
      sort($bundles);
      $this->multiStageSectionBundles = $bundles;
    }

    if ($this->multiStageSectionBundles === []) {
      return FALSE;
    }

    return in_array($section_bundle, $this->multiStageSectionBundles, TRUE);
  }

  /**
   * Pointer fill logic:
   * - Always fill field_work_order first.
   * - Only fill 2nd/3rd/4th when multistage is explicitly requested AND allowed.
   */
  private function determinePointerFieldToFill(EntityInterface $section, bool $fill_multistage): ?string {
    if ($section->hasField('field_work_order') && $section->get('field_work_order')->isEmpty()) {
      return 'field_work_order';
    }
    if (!$fill_multistage) {
      return NULL;
    }
    foreach (['field_2nd_work_order', 'field_3rd_work_order', 'field_4th_work_order'] as $field_name) {
      if ($section->hasField($field_name) && $section->get($field_name)->isEmpty()) {
        return $field_name;
      }
    }
    return NULL;
  }

  private function extractEstimatedPriceFromSection(EntityInterface $section): ?float {
    if (!$section->hasField('field_estimate') || $section->get('field_estimate')->isEmpty()) {
      return NULL;
    }
    $raw = trim((string) $section->get('field_estimate')->value);
    if ($raw === '') {
      return NULL;
    }
    if (strpos($raw, '-') !== FALSE) {
      if (preg_match('/(\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)/', $raw, $m)) {
        return (float) $m[2];
      }
      return NULL;
    }
    if (preg_match('/\d+(?:\.\d+)?/', $raw, $m)) {
      return (float) $m[0];
    }
    return NULL;
  }

  /**
   * Global fallback Work To Do description.
   *
   * Desired format:
   * <p>{contract_year} — {Service Name} as needed.<br><br>See Contract #{contract_id} for details.</p>
   */
  private function buildWorkTodoDescriptionHtml(EntityInterface $contract, string $service_label): ?string {
    $contract_id = (int) $contract->id();

    $year = '';
    if ($contract->hasField('field_contract_year') && !$contract->get('field_contract_year')->isEmpty()) {
      $year = trim((string) $contract->get('field_contract_year')->value);
    }

    $prefix = ($year !== '') ? "{$year} — " : '';
    $service_label = trim($service_label);
    $service_part = ($service_label !== '') ? "{$service_label} as needed." : "Service as needed.";

    return "<p>{$prefix}{$service_part}<br><br>See Contract #{$contract_id} for details.</p>";
  }

  private function inferSeasonFromString(string $s): ?string {
    $haystack = strtolower(str_replace(['-', ' '], '_', $s));
    $tokens = array_keys(self::SEASON_TOKENS);
    usort($tokens, function ($a, $b) {
      return strlen($b) <=> strlen($a);
    });

    foreach ($tokens as $token) {
      if (strpos($haystack, $token) !== FALSE) {
        return self::SEASON_TOKENS[$token];
      }
    }
    return NULL;
  }

  private function normalizeSeasonLabel(string $label): string {
    $l = strtolower(trim($label));
    $l = str_replace(['_', '-'], ' ', $l);
    $l = preg_replace('/\s+/', ' ', $l);

    $normalized = strtolower(str_replace(' ', '_', $l));
    if (isset(self::SEASON_TOKENS[$normalized])) {
      return self::SEASON_TOKENS[$normalized];
    }

    return ucwords($l);
  }

  private function workOrderHasField(string $bundle, string $field_name): bool {
    try {
      $tmp = $this->etm->getStorage('work_order')->create(['type' => $bundle]);
      return $tmp->hasField($field_name);
    }
    catch (\Throwable $e) {
      return FALSE;
    }
  }

}
