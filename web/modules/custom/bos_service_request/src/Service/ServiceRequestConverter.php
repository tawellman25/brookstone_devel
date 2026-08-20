<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Service;

use Drupal\bos_wo_intake\Service\WorkOrderIntakeService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Approve a service request → create the real Work Order.
 *
 * The ONLY place a public request becomes execution, and only by an explicit
 * office action (Gate 4 route calls this). Locked, transactional, idempotent:
 * a double-click / replay / direct URL re-run creates no second Work Order.
 *
 * WO creation is delegated to bos_wo_intake — this class contains NO Work Order
 * creation logic of its own.
 */
final class ServiceRequestConverter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly WorkOrderIntakeService $intake,
    private readonly ServiceRequestEligibility $eligibility,
    private readonly ServiceRequestStatusResolver $statusResolver,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @return array{status: string, work_order_id?: int, message?: string, eligibility?: string}
   *   status: converted | already_converted | not_eligible | no_property | locked | error
   */
  public function convert(ContentEntityInterface $request, AccountInterface $actor): array {
    $id = (int) $request->id();
    $lockName = 'service_request:' . $id . ':convert';
    if (!$this->lock->acquire($lockName, 30)) {
      return ['status' => 'locked', 'message' => 'Another conversion of this request is in progress.'];
    }

    try {
      // Idempotency: reload the authoritative row.
      $request = $this->entityTypeManager->getStorage('service_request')->loadUnchanged($id);
      if (!$request) {
        return ['status' => 'error', 'message' => 'Request not found.'];
      }
      $convertedTid = $this->statusResolver->tid(ServiceRequestStatusResolver::CONVERTED);
      $alreadyConverted = ($request->hasField('field_request_status') && !$request->get('field_request_status')->isEmpty()
          && (int) $request->get('field_request_status')->target_id === $convertedTid)
        || ($request->hasField('field_work_order') && !$request->get('field_work_order')->isEmpty());
      if ($alreadyConverted) {
        $existing = (!$request->get('field_work_order')->isEmpty()) ? (int) $request->get('field_work_order')->target_id : NULL;
        return ['status' => 'already_converted', 'work_order_id' => $existing];
      }

      $propertyId = ($request->hasField('field_property') && !$request->get('field_property')->isEmpty())
        ? (int) $request->get('field_property')->target_id : 0;
      $serviceTermId = ($request->hasField('field_service') && !$request->get('field_service')->isEmpty())
        ? (int) $request->get('field_service')->target_id : 0;
      $serviceYear = ($request->hasField('field_service_year') && !$request->get('field_service_year')->isEmpty())
        ? (int) $request->get('field_service_year')->value : 0;

      if (!$propertyId) {
        return ['status' => 'no_property', 'message' => 'Assign a property before converting.'];
      }
      if (!$serviceTermId) {
        return ['status' => 'error', 'message' => 'Request has no service term.'];
      }

      // Re-evaluate — state may have changed since submission. Exclude this
      // request from the duplicate check so it does not detect itself.
      $elig = $this->eligibility->evaluate($propertyId, $serviceTermId, $serviceYear, $id);
      if (!$elig->isEligible()) {
        return [
          'status' => 'not_eligible',
          'eligibility' => $elig->outcome,
          'message' => 'No longer eligible to convert (' . $elig->outcome . '). Use Mark Already Covered / Duplicate instead.',
        ];
      }

      $connection = \Drupal::database();
      $tx = $connection->startTransaction();
      try {
        // 1. Create the Work Order (delegated).
        $result = $this->intake->createBareWorkOrder($propertyId, $serviceTermId);
        if (empty($result['success']) || empty($result['work_order']['id'])) {
          throw new \RuntimeException('createBareWorkOrder failed: ' . ($result['error']['message'] ?? 'unknown'));
        }
        $woId = (int) $result['work_order']['id'];
        $woStorage = $this->entityTypeManager->getStorage('work_order');
        $wo = $woStorage->loadUnchanged($woId);

        // 2. Work-to-be-done + contract link on the WO.
        $todo = $this->buildWorkTodo($request);
        if ($todo !== '' && $wo->hasField('field_work_todo_description')) {
          $wo->set('field_work_todo_description', ['value' => $todo, 'format' => 'full_html']);
        }
        $contractId = $this->currentResidentialContractId($propertyId, $serviceYear);
        if ($contractId && $wo->hasField('field_contract')) {
          $wo->set('field_contract', $contractId);
        }
        $wo->save();

        // 3. Traceability note (explicit createAccess — bare save bypasses role).
        $this->createTraceNote($woId, $request, $actor);

        // 4. Write back to the request.
        $request->set('field_work_order', $woId);
        $request->set('field_converted_by', $actor->id());
        $request->set('field_converted_on', $this->time->getRequestTime());
        $request->set('field_request_status', $convertedTid);
        $request->save();
      }
      catch (\Throwable $e) {
        $tx->rollBack();
        $this->logger->error('Service request @id conversion failed: @msg', ['@id' => $id, '@msg' => $e->getMessage()]);
        return ['status' => 'error', 'message' => 'Conversion failed: ' . $e->getMessage()];
      }

      $this->logger->notice('Service request @id converted to work order @wo by uid @uid.', [
        '@id' => $id, '@wo' => $woId, '@uid' => $actor->id(),
      ]);
      return ['status' => 'converted', 'work_order_id' => $woId];
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Verbatim work-to-be-done text from the submitter's own words.
   */
  private function buildWorkTodo(ContentEntityInterface $request): string {
    $parts = [];
    foreach (['field_customer_notes', 'field_access_notes'] as $f) {
      if ($request->hasField($f) && !$request->get($f)->isEmpty()) {
        $parts[] = trim((string) $request->get($f)->value);
      }
    }
    return implode("\n\n", array_filter($parts));
  }

  /**
   * Append a system note recording the request → WO provenance (§14: attribution
   * must survive conversion). Uses the Gate 2A note convention.
   */
  private function createTraceNote(int $woId, ContentEntityInterface $request, AccountInterface $actor): void {
    $noteStorage = $this->entityTypeManager->getStorage('wo_notes');
    if (!$this->entityTypeManager->getAccessControlHandler('wo_notes')
      ->createAccess('note', $actor, [], TRUE)->isAllowed()) {
      $this->logger->warning('Skipped trace note for WO @wo — uid @uid lacks wo_notes create.', ['@wo' => $woId, '@uid' => $actor->id()]);
      return;
    }
    $get = fn(string $f) => ($request->hasField($f) && !$request->get($f)->isEmpty()) ? trim((string) $request->get($f)->value) : '';
    $ref = $get('field_public_ref');
    $lines = array_filter([
      'Created from Service Request ' . ($ref !== '' ? $ref : '#' . $request->id()) . ' (#' . $request->id() . ').',
      $get('field_submitted_name') !== '' ? 'Submitted by: ' . $get('field_submitted_name') : '',
      $get('field_submitted_address') !== '' ? 'Address given: ' . $get('field_submitted_address') . ' ' . $get('field_submitted_zip') : '',
      $get('field_customer_notes') !== '' ? 'Customer notes: ' . $get('field_customer_notes') : '',
      $get('field_access_notes') !== '' ? 'Access notes: ' . $get('field_access_notes') : '',
      trim(($get('field_source') !== '' ? 'Source: ' . $get('field_source') : '') . ($get('field_campaign') !== '' ? '  Campaign: ' . $get('field_campaign') : '')),
    ]);
    $note = $noteStorage->create([
      'type' => 'note',
      'field_work_order' => $woId,
      'field_note_text' => ['value' => implode("\n", $lines), 'format' => 'basic_html'],
      'field_is_system_note' => TRUE,
      'field_note_kind' => 'manual',
    ]);
    $note->save();
  }

  /**
   * Current-year residential contract id for the property, if any.
   */
  private function currentResidentialContractId(int $propertyId, int $serviceYear): ?int {
    $ids = $this->entityTypeManager->getStorage('contracts')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'residential')
      ->condition('field_property', $propertyId)
      ->condition('field_contract_year', $serviceYear)
      ->sort('id', 'ASC')
      ->range(0, 1)
      ->execute();
    return $ids ? (int) reset($ids) : NULL;
  }

}
