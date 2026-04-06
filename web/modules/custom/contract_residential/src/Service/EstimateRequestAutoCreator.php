<?php

declare(strict_types=1);

namespace Drupal\contract_residential\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\contract_residential\ContractActionLogWriter;
use Drupal\taxonomy\TermInterface;

/**
 * Creates or reuses an Estimate Request when a Contract Section is "Request Quote".
 *
 * Design:
 * - One Estimate Request per Contract (not per section).
 * - Multiple "Request Quote" sections on the same contract share one request.
 * - Each section's service is added to the request's field_service (multi-value).
 * - The section's field_estimate_request points to the shared request.
 * - field_contract_section lives on the Estimate entity, not the request.
 *
 * Rules:
 * - Trigger: contract_sections.field_do_you_want == 3 (Request Quote)
 * - If field_estimate_request already set -> repair missing data if needed
 * - Otherwise: find or create the request for this contract, add service, set pointer
 * - Must be idempotent (no duplicates on repeated saves)
 */
final class EstimateRequestAutoCreator {

  private const SECTION_ENTITY_TYPE = 'contract_sections';
  private const REQUEST_ENTITY_TYPE = 'estimate_request';
  private const REQUEST_BUNDLE = 'standard';

  private const SECTION_DO_YOU_WANT_FIELD = 'field_do_you_want';
  private const SECTION_REQUEST_POINTER_FIELD = 'field_estimate_request';
  private const DO_YOU_WANT_REQUEST_QUOTE_VALUE = '3';

  private const REQ_FIELD_CONTRACT = 'field_contract';
  private const REQ_FIELD_PRIORITY = 'field_priority';
  private const REQ_FIELD_PROPERTY = 'field_property';
  private const REQ_FIELD_OWNER = 'field_owner';
  private const REQ_FIELD_SERVICE = 'field_service';
  private const REQ_FIELD_STATUS = 'field_status';

  private const CONTRACT_FIELD_PROPERTY = 'field_property';
  private const CONTRACT_FIELD_OWNER = 'field_property_owner';

  private const DEFAULT_PRIORITY = 'normal';
  private const DEFAULT_STATUS_NAME = 'New';
  private const DEFAULT_STATUS_VID = 'estimate_request_status';

  /**
   * Simple recursion guard: section id => bool.
   *
   * @var array<int, bool>
   */
  private static array $savingSectionGuard = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Apply rule to a just-saved Contract Section.
   *
   * Call from hook_entity_insert and hook_entity_update.
   *
   * @return int|null
   *   The created estimate request ID, or NULL if no request was created.
   */
  public function apply(EntityInterface $section): ?int {
    if ($section->getEntityTypeId() !== self::SECTION_ENTITY_TYPE) {
      return NULL;
    }

    if (!$section->hasField(self::SECTION_DO_YOU_WANT_FIELD) || !$section->hasField(self::SECTION_REQUEST_POINTER_FIELD)) {
      return NULL;
    }

    $sid = (int) $section->id();
    if ($sid <= 0) {
      return NULL;
    }

    // Recursion guard (we re-save the section to write the pointer).
    if (!empty(self::$savingSectionGuard[$sid])) {
      return NULL;
    }

    // Only trigger when saved as Request Quote.
    $trigger_value = (string) ($section->get(self::SECTION_DO_YOU_WANT_FIELD)->value ?? '');
    if ($trigger_value !== self::DO_YOU_WANT_REQUEST_QUOTE_VALUE) {
      return NULL;
    }

    // If pointer already set, repair missing data on the existing request
    // (handles timing gap where section was saved before field_contract was
    // populated by _contract_residential_sync_section_parent_links).
    $existing_pointer = (int) ($section->get(self::SECTION_REQUEST_POINTER_FIELD)->target_id ?? 0);
    if ($existing_pointer > 0) {
      $this->repairExistingRequest($existing_pointer, $section);
      return NULL;
    }

    // Resolve the contract for this section.
    $contract_id = $this->resolveContractId($section);

    // Try to find an existing request for this contract.
    if ($contract_id > 0) {
      $existing_req_id = $this->findExistingRequestByContract($contract_id);
      if ($existing_req_id > 0) {
        $this->addServiceToRequest($existing_req_id, $section);
        $this->writePointerBackToSection($section, $existing_req_id);
        return NULL;
      }
    }

    // Fallback: if pointer missing but a request already exists referencing
    // this section, reuse it.
    $existing_req_id = $this->findExistingRequestIdBySection($sid);
    if ($existing_req_id > 0) {
      $this->writePointerBackToSection($section, $existing_req_id);
      return NULL;
    }

    // Create new request for this contract.
    $req_id = $this->createEstimateRequestForSection($section, $contract_id);
    if ($req_id > 0) {
      $this->writePointerBackToSection($section, $req_id);
      return $req_id;
    }

    return NULL;
  }

  /**
   * Clean up contract section back-reference when an Estimate Request is deleted.
   */
  public function onRequestDeleted(EntityInterface $request): void {
    if ($request->getEntityTypeId() !== self::REQUEST_ENTITY_TYPE) {
      return;
    }

    // Find all contract sections that point to this request and clear them.
    $section_storage = $this->entityTypeManager->getStorage(self::SECTION_ENTITY_TYPE);
    $section_ids = $section_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition(self::SECTION_REQUEST_POINTER_FIELD . '.target_id', (int) $request->id())
      ->execute();

    foreach ($section_ids as $section_id) {
      $section = $section_storage->load($section_id);
      if ($section === NULL || !$section->hasField(self::SECTION_REQUEST_POINTER_FIELD)) {
        continue;
      }

      $pointer = (int) ($section->get(self::SECTION_REQUEST_POINTER_FIELD)->target_id ?? 0);
      if ($pointer !== (int) $request->id()) {
        continue;
      }

      self::$savingSectionGuard[(int) $section_id] = TRUE;
      try {
        $section->set(self::SECTION_REQUEST_POINTER_FIELD, NULL);
        $section->save();

        $this->loggerFactory->get('contract_residential')
          ->info('Cleared Estimate Request pointer on Contract Section @sid (request @rid deleted).', [
            '@sid' => $section_id,
            '@rid' => (int) $request->id(),
          ]);
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('contract_residential')
          ->error('Failed clearing Estimate Request pointer on Contract Section @sid: @msg', [
            '@sid' => $section_id,
            '@msg' => $e->getMessage(),
          ]);
      }
      finally {
        unset(self::$savingSectionGuard[(int) $section_id]);
      }
    }
  }

  /**
   * Resolve the contract ID for a section (direct field or reverse lookup).
   */
  private function resolveContractId(EntityInterface $section): int {
    // Direct: field_contract on the section.
    if ($section->hasField(self::REQ_FIELD_CONTRACT) && !$section->get(self::REQ_FIELD_CONTRACT)->isEmpty()) {
      return (int) $section->get(self::REQ_FIELD_CONTRACT)->target_id;
    }

    // Fallback field name.
    if ($section->hasField('field_parent_contract') && !$section->get('field_parent_contract')->isEmpty()) {
      return (int) $section->get('field_parent_contract')->target_id;
    }

    // Reverse lookup: find the contract that references this section via slot field.
    return $this->findContractBySectionReverse((int) $section->id(), $section->bundle());
  }

  /**
   * Find an existing Estimate Request for a contract.
   */
  private function findExistingRequestByContract(int $contract_id): int {
    $ids = $this->entityTypeManager->getStorage(self::REQUEST_ENTITY_TYPE)
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::REQUEST_BUNDLE)
      ->condition(self::REQ_FIELD_CONTRACT . '.target_id', $contract_id)
      ->range(0, 1)
      ->sort('id', 'DESC')
      ->execute();

    if (empty($ids)) {
      return 0;
    }

    return (int) array_values($ids)[0];
  }

  /**
   * Add a section's service to an existing request's field_service.
   */
  private function addServiceToRequest(int $request_id, EntityInterface $section): void {
    $request = $this->entityTypeManager->getStorage(self::REQUEST_ENTITY_TYPE)->load($request_id);
    if ($request === NULL || !$request->hasField(self::REQ_FIELD_SERVICE)) {
      return;
    }

    // Get the service term from the section.
    if (!$section->hasField(self::REQ_FIELD_SERVICE) || $section->get(self::REQ_FIELD_SERVICE)->isEmpty()) {
      return;
    }
    $new_tid = (int) $section->get(self::REQ_FIELD_SERVICE)->target_id;
    if ($new_tid <= 0) {
      return;
    }

    // Check if this service is already on the request.
    $existing_tids = [];
    foreach ($request->get(self::REQ_FIELD_SERVICE)->getValue() as $item) {
      $existing_tids[] = (int) ($item['target_id'] ?? 0);
    }

    if (in_array($new_tid, $existing_tids, TRUE)) {
      return;
    }

    // Add the service.
    $existing_tids[] = $new_tid;
    $request->set(self::REQ_FIELD_SERVICE, array_map(fn($tid) => ['target_id' => $tid], $existing_tids));

    try {
      $request->save();
      $this->loggerFactory->get('contract_residential')
        ->info('Added service tid @tid to Estimate Request @rid from section @sid.', [
          '@tid' => $new_tid,
          '@rid' => $request_id,
          '@sid' => (int) $section->id(),
        ]);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('contract_residential')
        ->error('Failed adding service to Estimate Request @rid: @msg', [
          '@rid' => $request_id,
          '@msg' => $e->getMessage(),
        ]);
    }
  }

  /**
   * Repair an existing Estimate Request that is missing contract/property/owner.
   *
   * Handles the IEF timing gap: the section is saved (and the request created)
   * before _contract_residential_sync_section_parent_links populates
   * field_contract. On the subsequent section re-save we now have the data.
   */
  private function repairExistingRequest(int $request_id, EntityInterface $section): void {
    $storage = $this->entityTypeManager->getStorage(self::REQUEST_ENTITY_TYPE);
    $request = $storage->load($request_id);
    if ($request === NULL) {
      return;
    }

    $contract_id = $this->resolveContractId($section);
    if ($contract_id <= 0) {
      return;
    }

    // Check which fields need filling.
    $needs_contract = $request->hasField(self::REQ_FIELD_CONTRACT) && $request->get(self::REQ_FIELD_CONTRACT)->isEmpty();
    $needs_property = $request->hasField(self::REQ_FIELD_PROPERTY) && $request->get(self::REQ_FIELD_PROPERTY)->isEmpty();
    $needs_owner = $request->hasField(self::REQ_FIELD_OWNER) && $request->get(self::REQ_FIELD_OWNER)->isEmpty();

    // Also ensure this section's service is on the request.
    $needs_service = FALSE;
    if ($section->hasField(self::REQ_FIELD_SERVICE) && !$section->get(self::REQ_FIELD_SERVICE)->isEmpty()) {
      $section_tid = (int) $section->get(self::REQ_FIELD_SERVICE)->target_id;
      if ($section_tid > 0 && $request->hasField(self::REQ_FIELD_SERVICE)) {
        $existing_tids = array_map(
          fn($item) => (int) ($item['target_id'] ?? 0),
          $request->get(self::REQ_FIELD_SERVICE)->getValue()
        );
        if (!in_array($section_tid, $existing_tids, TRUE)) {
          $needs_service = TRUE;
        }
      }
    }

    if (!$needs_contract && !$needs_property && !$needs_owner && !$needs_service) {
      return;
    }

    $contract = $this->entityTypeManager->getStorage('contracts')->load($contract_id);
    if ($contract === NULL) {
      return;
    }

    if ($needs_contract) {
      $request->set(self::REQ_FIELD_CONTRACT, ['target_id' => $contract_id]);
    }
    if ($needs_property && $contract->hasField(self::CONTRACT_FIELD_PROPERTY)) {
      $pid = (int) ($contract->get(self::CONTRACT_FIELD_PROPERTY)->target_id ?? 0);
      if ($pid > 0) {
        $request->set(self::REQ_FIELD_PROPERTY, ['target_id' => $pid]);
      }
    }
    if ($needs_owner && $contract->hasField(self::CONTRACT_FIELD_OWNER)) {
      $uid = (int) ($contract->get(self::CONTRACT_FIELD_OWNER)->target_id ?? 0);
      if ($uid > 0) {
        $request->set(self::REQ_FIELD_OWNER, ['target_id' => $uid]);
      }
    }
    if ($needs_service) {
      $current = $request->get(self::REQ_FIELD_SERVICE)->getValue();
      $current[] = ['target_id' => $section_tid];
      $request->set(self::REQ_FIELD_SERVICE, $current);
    }

    try {
      $request->save();
      $this->loggerFactory->get('contract_residential')
        ->info('Repaired Estimate Request @rid: filled data from Contract @cid.', [
          '@rid' => $request_id,
          '@cid' => $contract_id,
        ]);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('contract_residential')
        ->error('Failed repairing Estimate Request @rid: @msg', [
          '@rid' => $request_id,
          '@msg' => $e->getMessage(),
        ]);
    }
  }

  /**
   * Find an existing Estimate Request by contract section reference.
   *
   * Legacy fallback for requests created before the one-per-contract pattern.
   */
  private function findExistingRequestIdBySection(int $section_id): int {
    $storage = $this->entityTypeManager->getStorage(self::REQUEST_ENTITY_TYPE);

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::REQUEST_BUNDLE)
      ->condition('field_contract_section.target_id', $section_id)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return 0;
    }

    return (int) array_values($ids)[0];
  }

  /**
   * Create a new Estimate Request and return its id.
   */
  private function createEstimateRequestForSection(EntityInterface $section, int $contract_id): int {
    $storage = $this->entityTypeManager->getStorage(self::REQUEST_ENTITY_TYPE);

    $values = [
      'type' => self::REQUEST_BUNDLE,
      self::REQ_FIELD_PRIORITY => self::DEFAULT_PRIORITY,
    ];

    // Set contract.
    if ($contract_id > 0) {
      $values[self::REQ_FIELD_CONTRACT] = ['target_id' => $contract_id];
    }

    // Populate field_property and field_owner from the contract.
    if ($contract_id > 0) {
      $contract = $this->entityTypeManager->getStorage('contracts')->load($contract_id);
      if ($contract !== NULL) {
        if ($contract->hasField(self::CONTRACT_FIELD_PROPERTY)) {
          $pid = (int) ($contract->get(self::CONTRACT_FIELD_PROPERTY)->target_id ?? 0);
          if ($pid > 0) {
            $values[self::REQ_FIELD_PROPERTY] = ['target_id' => $pid];
          }
        }
        if ($contract->hasField(self::CONTRACT_FIELD_OWNER)) {
          $uid = (int) ($contract->get(self::CONTRACT_FIELD_OWNER)->target_id ?? 0);
          if ($uid > 0) {
            $values[self::REQ_FIELD_OWNER] = ['target_id' => $uid];
          }
        }
      }
    }

    // Populate field_service from the section.
    if ($section->hasField(self::REQ_FIELD_SERVICE) && !$section->get(self::REQ_FIELD_SERVICE)->isEmpty()) {
      $tid = (int) $section->get(self::REQ_FIELD_SERVICE)->target_id;
      if ($tid > 0) {
        $values[self::REQ_FIELD_SERVICE] = [['target_id' => $tid]];
      }
    }

    // Set Status = "New".
    $new_term = $this->loadStatusTerm(self::DEFAULT_STATUS_VID, self::DEFAULT_STATUS_NAME);
    if ($new_term instanceof TermInterface) {
      $values[self::REQ_FIELD_STATUS] = ['target_id' => (int) $new_term->id()];
    }

    $values['title'] = 'Estimate Request - Pending';

    try {
      $req = $storage->create($values);
      $req->save();

      $rid = (int) $req->id();

      // Update title with entity ID.
      $expected_title = 'Estimate Request #' . $rid;
      if ($req->label() !== $expected_title) {
        $req->set('title', $expected_title);
        $req->save();
      }

      // Log to contract action log.
      if ($contract_id > 0) {
        try {
          $contract = $this->entityTypeManager->getStorage('contracts')->load($contract_id);
          if ($contract !== NULL) {
            $log_context = ['estimate_request_id' => $rid];
            $service_tid = (int) ($values[self::REQ_FIELD_SERVICE][0]['target_id'] ?? 0);
            if ($service_tid > 0) {
              $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($service_tid);
              if ($term !== NULL) {
                $log_context['service'] = $term->label();
              }
            }
            $log_context['url'] = $req->toUrl('canonical')->setAbsolute(TRUE)->toString();
            ContractActionLogWriter::writeEvent($contract, 'estimate_request_created', 'system', $log_context);
          }
        }
        catch (\Throwable $e) {
          $this->loggerFactory->get('contract_residential')
            ->warning('Failed writing action log for Estimate Request @rid: @msg', [
              '@rid' => $rid,
              '@msg' => $e->getMessage(),
            ]);
        }
      }

      $this->loggerFactory->get('contract_residential')
        ->info('Created Estimate Request @rid for Contract @cid (section @sid, Request Quote).', [
          '@rid' => $rid,
          '@cid' => $contract_id,
          '@sid' => (int) $section->id(),
        ]);

      return $rid;
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('contract_residential')
        ->error('Failed creating Estimate Request for Contract Section @sid: @msg', [
          '@sid' => (int) $section->id(),
          '@msg' => $e->getMessage(),
        ]);
      return 0;
    }
  }

  /**
   * Write the request pointer back to the section safely (no loops).
   */
  private function writePointerBackToSection(EntityInterface $section, int $request_id): void {
    $sid = (int) $section->id();
    if ($sid <= 0 || $request_id <= 0) {
      return;
    }

    self::$savingSectionGuard[$sid] = TRUE;
    try {
      $section->set(self::SECTION_REQUEST_POINTER_FIELD, ['target_id' => $request_id]);
      $section->save();

      $this->loggerFactory->get('contract_residential')
        ->info('Linked section @sid to Estimate Request @rid.', [
          '@rid' => $request_id,
          '@sid' => $sid,
        ]);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('contract_residential')
        ->error('Failed writing Estimate Request pointer @rid to Contract Section @sid: @msg', [
          '@rid' => $request_id,
          '@sid' => $sid,
          '@msg' => $e->getMessage(),
        ]);
    }
    finally {
      unset(self::$savingSectionGuard[$sid]);
    }
  }

  /**
   * Section bundle → contract slot field mapping.
   *
   * Mirrors bos_contract_sections_attach. Used as a reverse lookup when
   * field_contract is not yet populated on the section.
   */
  private const SECTION_BUNDLE_TO_CONTRACT_FIELD = [
    'aerating_of_lawn' => 'field_aerating_of_lawn',
    'aspen_twig_gall_control' => 'field_aspen_twig_gall_control',
    'christmas_decorations' => 'field_christmas_decorations',
    'cooley_spruce_gall_treatment' => 'field_cooley_spruce_gall_treatme',
    'deciduous_bore_treatment' => 'field_deciduous_bore_treatment',
    'deer_protection_wire' => 'field_deer_protection_wire_for_t',
    'dethatching_of_lawn_areas' => 'field_dethatching_of_lawn_areas',
    'dormant_oil_spray' => 'field_dormant_oil_spray',
    'fall_cleanup' => 'field_fall_cleanup',
    'fertilizing_of_shrubs_and_trees' => 'field_fertilizing_trees_shrubs',
    'grub_prevention_on_lawn' => 'field_grub_prevention_on_lawn',
    'ips_beetle_on_pinion_pine' => 'field_ips_beetle_on_pinion_pine',
    'irrigation_check_ups' => 'field_irrigation_check_ups',
    'irrigation_shut_down' => 'field_irrigation_shut_down',
    'irrigation_start_up' => 'field_irrigation_start_up',
    'lawn_fertilizing' => 'field_lawn_fertilizing_broadleaf',
    'lawn_mowing_and_trimming' => 'field_lawn_mowing_and_trimming',
    'pre_emergent' => 'field_pre_emergent',
    'spring_cleanup' => 'field_spring_cleanup',
    'summer_hedge_shrub_pruning' => 'field_summer_hedge_shrub_pruning',
    'trunk_bore_prevention' => 'field_trunk_bore_prevention',
    'weed_spraying_landscape_beds' => 'field_weed_spraying_of_landscape',
    'weed_spraying_of_misc_areas' => 'field_weed_spraying_of_misc_area',
    'winter_pruning' => 'field_winter_pruning',
  ];

  /**
   * Find the residential contract that references a section via its slot field.
   */
  private function findContractBySectionReverse(int $section_id, string $section_bundle): int {
    $slot_field = self::SECTION_BUNDLE_TO_CONTRACT_FIELD[$section_bundle] ?? NULL;
    if ($slot_field === NULL) {
      return 0;
    }

    $ids = $this->entityTypeManager->getStorage('contracts')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'residential')
      ->condition($slot_field . '.target_id', $section_id)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return 0;
    }

    return (int) array_values($ids)[0];
  }

  /**
   * Load a taxonomy term by vocabulary id + term name.
   */
  private function loadStatusTerm(string $vid, string $name): ?TermInterface {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vid)
      ->condition('name', $name)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    $term = $storage->load(array_values($ids)[0]);
    return ($term instanceof TermInterface) ? $term : NULL;
  }

}
