<?php

declare(strict_types=1);

namespace Drupal\bos_wo_intake\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Transport-agnostic Work Order intake logic.
 *
 * Holds ALL business logic so the REST resource (Gate 1), the Gate 2
 * natural-language + dedup layer, and any future MCP tool share one code path.
 * Methods return a structured array: on success
 *   ['success' => TRUE, 'work_order' => [...], 'http' => 201]
 * on failure
 *   ['success' => FALSE, 'error' => ['code' => '...', 'message' => '...'], 'http' => 4xx].
 * Callers map ['http'] to the transport status and drop it from the body.
 */
final class WorkOrderIntakeService {

  /**
   * WO status term: "Open" (vocab wo_status). Verified live 2026-07-04.
   */
  private const STATUS_OPEN_TID = 1089;

  /**
   * Legacy WO bundle being phased out — never create via the API.
   */
  private const FORBIDDEN_BUNDLE = 'estimate';

  /**
   * The service account intake writes run as; also the subject of the
   * explicit createAccess() gate that makes system_integration bite.
   */
  private const ACCOUNT_NAME = 'cowork-connect';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Create a bare Work Order from a property id + a service term id.
   *
   * Gate 1 only: IDs come in directly (no name/NL resolution), no duplicate
   * check, no child entities. See the module doc for Gate 2 scope.
   */
  public function createBareWorkOrder(int $propertyId, int $serviceTermId): array {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');

    // (a) Service term must exist and be a work-order service.
    $term = $termStorage->load($serviceTermId);
    if (!$term || $term->bundle() !== 'services') {
      return $this->error('service_not_work_order', 'Service term not found or not a service.', 422);
    }
    $isWoService = $term->hasField('field_work_order_service')
      && !$term->get('field_work_order_service')->isEmpty()
      && (bool) $term->get('field_work_order_service')->value;
    if (!$isWoService) {
      return $this->error('service_not_work_order', 'Service is not flagged as a work-order service.', 422);
    }

    // (b) Resolve the target WO bundle from the service.
    $bundle = $term->hasField('field_service_bundle') && !$term->get('field_service_bundle')->isEmpty()
      ? trim((string) $term->get('field_service_bundle')->value)
      : '';
    if ($bundle === '') {
      return $this->error('service_bundle_missing', 'Service has no work-order bundle mapping.', 422);
    }

    // (c) Never create the legacy estimate bundle (global create perm allows it;
    // block at this layer per work_order_api.md flag 6).
    if ($bundle === self::FORBIDDEN_BUNDLE) {
      return $this->error('estimate_bundle_forbidden', 'The estimate bundle cannot be created via the API.', 422);
    }

    // (d) Property must exist and be properties:property.
    $property = $this->entityTypeManager->getStorage('properties')->load($propertyId);
    if (!$property || $property->bundle() !== 'property') {
      return $this->error('property_not_found', 'Property not found.', 422);
    }

    // (e) EXPLICIT ACCESS GATE — this is what makes system_integration a real
    // boundary; a bare ->save() below bypasses entity access entirely.
    $account = $this->loadServiceAccount();
    if (!$account) {
      $this->logger->error('WO-intake: service account "@n" missing/blocked at access gate.', ['@n' => self::ACCOUNT_NAME]);
      return $this->error('access_denied', 'Service account unavailable.', 403);
    }
    $access = $this->entityTypeManager->getAccessControlHandler('work_order')
      ->createAccess($bundle, $account, [], TRUE);
    if (!$access->isAllowed()) {
      return $this->error('access_denied', 'Not permitted to create this work order.', 403);
    }

    // (f) Create + save via the normal path so wo_shared_work_order_insert()
    // heals the AEL sentinel title (programmatic double-save; no access check
    // needed there — see work_order_api.md B3).
    try {
      $wo = $this->entityTypeManager->getStorage('work_order')->create([
        'type' => $bundle,
        'field_property' => ['target_id' => $propertyId],
        'field_service' => ['target_id' => $serviceTermId],
        'field_status' => ['target_id' => self::STATUS_OPEN_TID],
      ]);
      $wo->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('WO-intake: create failed for bundle @b: @m', ['@b' => $bundle, '@m' => $e->getMessage()]);
      return $this->error('create_failed', 'Work order could not be created.', 500);
    }

    // (g) Reload and report the persisted state.
    $wo = $this->entityTypeManager->getStorage('work_order')->loadUnchanged($wo->id());
    $statusTid = $wo->get('field_status')->isEmpty() ? NULL : (int) $wo->get('field_status')->target_id;
    $statusLabel = ($statusTid && ($t = $termStorage->load($statusTid))) ? $t->label() : NULL;
    $woId = $wo->hasField('field_work_order_id') && !$wo->get('field_work_order_id')->isEmpty()
      ? (string) $wo->get('field_work_order_id')->value
      : NULL;

    $this->logger->info('WO-intake: created WO @id (@b) for property @p.', [
      '@id' => $wo->id(), '@b' => $bundle, '@p' => $propertyId,
    ]);

    return [
      'success' => TRUE,
      'work_order' => [
        'id' => (int) $wo->id(),
        'work_order_id' => $woId,
        'bundle' => $bundle,
        'status' => $statusLabel,
        'status_tid' => $statusTid,
      ],
      'http' => 201,
    ];
  }

  /**
   * Load the active service account, or NULL if missing/blocked.
   */
  private function loadServiceAccount() {
    $accounts = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['name' => self::ACCOUNT_NAME]);
    $account = $accounts ? reset($accounts) : NULL;
    return ($account && $account->isActive()) ? $account : NULL;
  }

  /**
   * Build a structured error result.
   */
  private function error(string $code, string $message, int $http): array {
    return [
      'success' => FALSE,
      'error' => ['code' => $code, 'message' => $message],
      'http' => $http,
    ];
  }

}
