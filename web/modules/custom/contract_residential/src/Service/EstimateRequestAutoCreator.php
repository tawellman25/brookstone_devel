<?php

declare(strict_types=1);

namespace Drupal\contract_residential\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Creates an Estimate Request when a Contract Section is set to "Request Quote".
 *
 * Rules:
 * - Trigger: contract_sections.field_do_you_want == 3 (Request Quote)
 * - If field_estimate_request already set -> do nothing (optionally sync pointers)
 * - If missing, create estimate_request (bundle: standard) and back-reference it
 * - Must be idempotent (no duplicates on repeated saves)
 */
final class EstimateRequestAutoCreator {

  /**
   * Contract section entity type id.
   *
   * Your field output shows "Target Type: contract_sections".
   */
  private const SECTION_ENTITY_TYPE = 'contract_sections';

  /**
   * Estimate request entity type id.
   */
  private const REQUEST_ENTITY_TYPE = 'estimate_request';

  /**
   * Estimate request bundle machine name.
   */
  private const REQUEST_BUNDLE = 'standard';

  /**
   * Contract section trigger field.
   */
  private const SECTION_DO_YOU_WANT_FIELD = 'field_do_you_want';

  /**
   * Contract section pointer to created request.
   */
  private const SECTION_REQUEST_POINTER_FIELD = 'field_estimate_request';

  /**
   * Request Quote stored value in field_do_you_want.
   */
  private const DO_YOU_WANT_REQUEST_QUOTE_VALUE = '3';

  /**
   * Estimate request fields.
   */
  private const REQ_FIELD_CONTRACT = 'field_contract';
  private const REQ_FIELD_SECTION = 'field_contract_section';
  private const REQ_FIELD_PRIORITY = 'field_priority';
  private const REQ_FIELD_PROPERTY = 'field_property';
  private const REQ_FIELD_OWNER = 'field_owner';
  private const REQ_FIELD_ASSIGNED_TO = 'field_assigned_to';
  private const REQ_FIELD_SERVICE = 'field_service';
  private const REQ_FIELD_STATUS = 'field_status';

  /**
   * Contract entity fields read to populate the estimate request.
   */
  private const CONTRACT_FIELD_PROPERTY = 'field_property';
  private const CONTRACT_FIELD_OWNER = 'field_property_owner';

  /**
   * Service term field that holds the default estimator user reference.
   */
  private const SERVICE_FIELD_DEFAULT_ESTIMATOR = 'field_default_estimator';

  /**
   * Defaults.
   */
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
   */
  public function apply(EntityInterface $section): void {
    // Only act on Contract Sections.
    if ($section->getEntityTypeId() !== self::SECTION_ENTITY_TYPE) {
      return;
    }

    // Must have trigger field + pointer field.
    if (!$section->hasField(self::SECTION_DO_YOU_WANT_FIELD) || !$section->hasField(self::SECTION_REQUEST_POINTER_FIELD)) {
      return;
    }

    $sid = (int) $section->id();
    if ($sid <= 0) {
      // Should not happen for insert/update hooks, but be safe.
      return;
    }

    // Recursion guard (we re-save the section to write the pointer).
    if (!empty(self::$savingSectionGuard[$sid])) {
      return;
    }

    // Only trigger when saved as Request Quote.
    $trigger_value = (string) ($section->get(self::SECTION_DO_YOU_WANT_FIELD)->value ?? '');
    if ($trigger_value !== self::DO_YOU_WANT_REQUEST_QUOTE_VALUE) {
      return;
    }

    // If pointer already set, nothing to do.
    $existing_pointer = (int) ($section->get(self::SECTION_REQUEST_POINTER_FIELD)->target_id ?? 0);
    if ($existing_pointer > 0) {
      return;
    }

    // Fallback: if pointer missing but a request already exists for this section, reuse it.
    $existing_req_id = $this->findExistingRequestIdBySection($sid);
    if ($existing_req_id > 0) {
      $this->writePointerBackToSection($section, $existing_req_id);
      return;
    }

    // Create new request.
    $req_id = $this->createEstimateRequestForSection($section);
    if ($req_id > 0) {
      $this->writePointerBackToSection($section, $req_id);
    }
  }

  /**
   * Find an existing Estimate Request id by contract section reference.
   */
  private function findExistingRequestIdBySection(int $section_id): int {
    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage(self::REQUEST_ENTITY_TYPE);

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::REQUEST_BUNDLE)
      ->condition(self::REQ_FIELD_SECTION . '.target_id', $section_id)
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
  private function createEstimateRequestForSection(EntityInterface $section): int {
    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage(self::REQUEST_ENTITY_TYPE);

    $values = [
      'type' => self::REQUEST_BUNDLE,
      self::REQ_FIELD_SECTION => ['target_id' => (int) $section->id()],
      self::REQ_FIELD_PRIORITY => self::DEFAULT_PRIORITY,
    ];

    // Populate field_contract from the section.
    if ($section->hasField(self::REQ_FIELD_CONTRACT)) {
      $cid = (int) ($section->get(self::REQ_FIELD_CONTRACT)->target_id ?? 0);
      if ($cid > 0) {
        $values[self::REQ_FIELD_CONTRACT] = ['target_id' => $cid];
      }
    }
    elseif ($section->hasField('field_parent_contract')) {
      $cid = (int) ($section->get('field_parent_contract')->target_id ?? 0);
      if ($cid > 0) {
        $values[self::REQ_FIELD_CONTRACT] = ['target_id' => $cid];
      }
    }

    // Populate field_property and field_owner by loading the resolved contract.
    $contract_id = (int) ($values[self::REQ_FIELD_CONTRACT]['target_id'] ?? 0);
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
    if ($section->hasField(self::REQ_FIELD_SERVICE)) {
      $tid = (int) ($section->get(self::REQ_FIELD_SERVICE)->target_id ?? 0);
      if ($tid > 0) {
        $values[self::REQ_FIELD_SERVICE] = ['target_id' => $tid];
      }
    }

    // Populate field_assigned_to from the service term's field_default_estimator.
    $service_tid = (int) ($values[self::REQ_FIELD_SERVICE]['target_id'] ?? 0);
    if ($service_tid > 0) {
      $service_term = $this->entityTypeManager->getStorage('taxonomy_term')->load($service_tid);
      if ($service_term !== NULL && $service_term->hasField(self::SERVICE_FIELD_DEFAULT_ESTIMATOR)) {
        $estimator_uid = (int) ($service_term->get(self::SERVICE_FIELD_DEFAULT_ESTIMATOR)->target_id ?? 0);
        if ($estimator_uid > 0) {
          $values[self::REQ_FIELD_ASSIGNED_TO] = ['target_id' => $estimator_uid];
        }
      }
    }

    // Set Status = "New" (term lookup by vid + name).
    $new_term = $this->loadStatusTerm(self::DEFAULT_STATUS_VID, self::DEFAULT_STATUS_NAME);
    if ($new_term instanceof TermInterface) {
      $values[self::REQ_FIELD_STATUS] = ['target_id' => (int) $new_term->id()];
    }

    // Optional: set a human-readable label/title if the entity supports it.
    // ECK often uses "title" as a base property; safe to set if present.
    $title = $this->buildRequestTitle($section);
    if (!empty($title)) {
      $values['title'] = $title;
    }

    try {
      $req = $storage->create($values);
      $req->save();

      $rid = (int) $req->id();
      $this->loggerFactory->get('contract_residential')
        ->info('Created Estimate Request @rid for Contract Section @sid (Request Quote).', [
          '@rid' => $rid,
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
        ->info('Back-referenced Estimate Request @rid to Contract Section @sid.', [
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
   * Load a taxonomy term by vocabulary id + term name.
   */
  private function loadStatusTerm(string $vid, string $name): ?TermInterface {
    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
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

  /**
   * Build a sensible title if the entity supports "title".
   */
  private function buildRequestTitle(EntityInterface $section): string {
    $sid = (int) $section->id();
    if ($sid <= 0) {
      return '';
    }

    $contract_id = 0;
    if ($section->hasField('field_contract')) {
      $contract_id = (int) ($section->get('field_contract')->target_id ?? 0);
    }
    elseif ($section->hasField('field_parent_contract')) {
      $contract_id = (int) ($section->get('field_parent_contract')->target_id ?? 0);
    }

    return $contract_id > 0
      ? sprintf('Estimate Request – Contract %d – Section %d', $contract_id, $sid)
      : sprintf('Estimate Request – Section %d', $sid);
  }

}
