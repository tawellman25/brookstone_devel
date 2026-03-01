<?php

declare(strict_types=1);

namespace Drupal\estimate_contract_residential\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Creates an Estimate Request when a Contract Section is set to "Request Quote".
 *
 * Trigger:
 * - contract_sections.field_do_you_want == '3'
 *
 * Idempotent:
 * - Uses contract_sections.field_estimate_request as 1:1 pointer
 * - Fallback query to find existing request by field_contract_section
 */
final class EstimateRequestAutoCreator {

  private const SECTION_ENTITY_TYPE = 'contract_sections';
  private const REQUEST_ENTITY_TYPE = 'estimate_request';
  private const REQUEST_BUNDLE = 'standard';

  private const SECTION_TRIGGER_FIELD = 'field_do_you_want';
  private const SECTION_POINTER_FIELD = 'field_estimate_request';
  private const TRIGGER_VALUE_REQUEST_QUOTE = '3';

  private const REQ_FIELD_CONTRACT = 'field_contract';
  private const REQ_FIELD_SECTION = 'field_contract_section';
  private const REQ_FIELD_PRIORITY = 'field_priority';
  private const REQ_FIELD_REQUESTED_BY = 'field_requested_by';
  private const REQ_FIELD_SERVICE = 'field_service';
  private const REQ_FIELD_STATUS = 'field_status';

  private const DEFAULT_PRIORITY = 'normal';
  private const DEFAULT_STATUS_VID = 'estimate_request_status';
  private const DEFAULT_STATUS_NAME = 'New';

  /** @var array<int,bool> */
  private static array $sectionSaveGuard = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public function apply(EntityInterface $section): void {
    if ($section->getEntityTypeId() !== self::SECTION_ENTITY_TYPE) {
      return;
    }
    if (!$section->hasField(self::SECTION_TRIGGER_FIELD) || !$section->hasField(self::SECTION_POINTER_FIELD)) {
      return;
    }

    $sid = (int) $section->id();
    if ($sid <= 0) {
      return;
    }

    if (!empty(self::$sectionSaveGuard[$sid])) {
      return;
    }

    $val = (string) ($section->get(self::SECTION_TRIGGER_FIELD)->value ?? '');
    if ($val !== self::TRIGGER_VALUE_REQUEST_QUOTE) {
      return;
    }

    // Already linked: done.
    $pointer = (int) ($section->get(self::SECTION_POINTER_FIELD)->target_id ?? 0);
    if ($pointer > 0) {
      return;
    }

    // Pointer missing but request exists: relink it.
    $existing = $this->findExistingRequestIdBySection($sid);
    if ($existing > 0) {
      $this->writePointerBack($section, $existing);
      return;
    }

    // Create.
    $rid = $this->createRequest($section);
    if ($rid > 0) {
      $this->writePointerBack($section, $rid);
    }
  }

  private function findExistingRequestIdBySection(int $section_id): int {
    $storage = $this->entityTypeManager->getStorage(self::REQUEST_ENTITY_TYPE);

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::REQUEST_BUNDLE)
      ->condition(self::REQ_FIELD_SECTION . '.target_id', $section_id)
      ->range(0, 1)
      ->execute();

    return empty($ids) ? 0 : (int) array_values($ids)[0];
  }

  private function createRequest(EntityInterface $section): int {
    $storage = $this->entityTypeManager->getStorage(self::REQUEST_ENTITY_TYPE);

    $values = [
      'type' => self::REQUEST_BUNDLE,
      'title' => $this->buildTitle($section),
      self::REQ_FIELD_SECTION => ['target_id' => (int) $section->id()],
      self::REQ_FIELD_PRIORITY => self::DEFAULT_PRIORITY,
      self::REQ_FIELD_REQUESTED_BY => ['target_id' => (int) $this->currentUser->id()],
    ];

    // Contract from section (your sections use field_contract).
    if ($section->hasField('field_contract') && !$section->get('field_contract')->isEmpty()) {
      $cid = (int) $section->get('field_contract')->target_id;
      if ($cid > 0) {
        $values[self::REQ_FIELD_CONTRACT] = ['target_id' => $cid];
      }
    }

    // Service if section has it (optional).
    if ($section->hasField('field_service') && !$section->get('field_service')->isEmpty()) {
      $tid = (int) $section->get('field_service')->target_id;
      if ($tid > 0) {
        $values[self::REQ_FIELD_SERVICE] = ['target_id' => $tid];
      }
    }

    // Status = New.
    $term = $this->loadStatusTerm(self::DEFAULT_STATUS_VID, self::DEFAULT_STATUS_NAME);
    if ($term instanceof TermInterface) {
      $values[self::REQ_FIELD_STATUS] = ['target_id' => (int) $term->id()];
    }

    try {
      $req = $storage->create($values);
      $req->save();

      $rid = (int) $req->id();
      $this->loggerFactory->get('estimate_contract_residential')->info(
        'Created Estimate Request @rid for Contract Section @sid.',
        ['@rid' => $rid, '@sid' => (int) $section->id()]
      );
      return $rid;
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('estimate_contract_residential')->error(
        'Failed creating Estimate Request for Contract Section @sid: @msg',
        ['@sid' => (int) $section->id(), '@msg' => $e->getMessage()]
      );
      return 0;
    }
  }

  private function writePointerBack(EntityInterface $section, int $request_id): void {
    $sid = (int) $section->id();
    if ($sid <= 0 || $request_id <= 0) {
      return;
    }

    self::$sectionSaveGuard[$sid] = TRUE;
    try {
      $section->set(self::SECTION_POINTER_FIELD, ['target_id' => $request_id]);
      $section->save();

      $this->loggerFactory->get('estimate_contract_residential')->info(
        'Back-referenced Estimate Request @rid to Contract Section @sid.',
        ['@rid' => $request_id, '@sid' => $sid]
      );
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('estimate_contract_residential')->error(
        'Failed writing request pointer @rid to Contract Section @sid: @msg',
        ['@rid' => $request_id, '@sid' => $sid, '@msg' => $e->getMessage()]
      );
    }
    finally {
      unset(self::$sectionSaveGuard[$sid]);
    }
  }

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

  private function buildTitle(EntityInterface $section): string {
    $sid = (int) $section->id();
    $cid = 0;

    if ($section->hasField('field_contract') && !$section->get('field_contract')->isEmpty()) {
      $cid = (int) $section->get('field_contract')->target_id;
    }

    return $cid > 0
      ? sprintf('Estimate Request – Contract %d – Section %d', $cid, $sid)
      : sprintf('Estimate Request – Section %d', $sid);
  }

}
