<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\estimate_intake\Service\EstimateRequestIntakeLookup;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * The single BOS eligibility authority for a service request.
 *
 * Consumed by the public form, the office convert action, and the defense-in-
 * depth presave guard. Evaluate in strict order, return the FIRST hit (§5).
 *
 * Coverage is NOT Work-Order-only: a contracted customer may already be covered
 * before any WO row exists — the contract-section layer is part of the authority.
 */
final class ServiceRequestEligibility {

  /**
   * Contract statuses that mean the year's contract is committed/covering —
   * i.e. work IS coming. {Approved 1123, Generate WO 1651, WO Created 1124,
   * Assigned 1125}. Excluded: every pre-approval state, On Hold (1126), Canceled
   * (1128), and — per P0.2 (asymmetric failure) — "Completed for the Year" (1127).
   *
   * 1127 asserts the season is FINISHED, not that coverage is coming. A customer
   * writing in against a 1127 contract is a DISAGREEMENT between two signals, not
   * proof of coverage. Telling them "you're already on our list" when no WO
   * exists risks a split manifold in November and a warranty argument. So 1127 is
   * NOT covering: we accept the request and flag it (contract_completed_for_year)
   * for a 30-second office check. See contractCompletedForYear().
   */
  public const COVERED_CONTRACT_STATUS_TIDS = [1123, 1651, 1124, 1125];

  /**
   * "Completed for the Year" — a soft/disagreement signal, never covering (P0.2).
   */
  public const CONTRACT_COMPLETED_FOR_YEAR_TID = 1127;

  /**
   * The ONLY Work Order status that does NOT block a new request: Canceled.
   * Every other status blocks (§5.3) — enumerated positively, never as a
   * NOT-IN(done set), which has already produced a production trap.
   */
  public const NON_BLOCKING_WO_STATUS_TIDS = [1098];

  /**
   * field_do_you_want values (list_string) that mean the customer WANTS it.
   * 1 = Yes, 4 = Accepted/Price Confirmed. 2 = No and 3 = Request Quote do NOT
   * count as coverage — a naive non-empty check would falsely mark "No" rows.
   */
  public const CONTRACT_WANTS = ['1', '4'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EstimateRequestIntakeLookup $ownerLookup,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ServiceRequestStatusResolver $statusResolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @param int|null $excludeRequestId A service_request id to exclude from the
   *   step-5 duplicate check — the converter passes the request being converted
   *   so it does not detect itself as a duplicate.
   *
   * @return EligibilityResult First-hit outcome.
   */
  public function evaluate(int $propertyId, int $serviceTermId, int $serviceYear, ?int $excludeRequestId = NULL): EligibilityResult {
    $propertyStorage = $this->entityTypeManager->getStorage('properties');
    $property = $propertyStorage->load($propertyId);
    if (!$property || $property->bundle() !== 'property') {
      return new EligibilityResult(EligibilityResult::NOT_ELIGIBLE, ['unmatched_property']);
    }

    // 1. Property field_no_services.
    if ($property->hasField('field_no_services') && (bool) $property->get('field_no_services')->value) {
      return new EligibilityResult(EligibilityResult::NOT_ELIGIBLE, ['no_services']);
    }

    // 2. Owner account credit_hold / do_not_schedule (on the User, via latest
    //    ownership_record — reuse estimate_intake's read-side resolver).
    $ownerUid = $this->ownerLookup->findLatestOwner($propertyId);
    if ($ownerUid && ($owner = User::load($ownerUid))) {
      $flags = [];
      if ($owner->hasField('field_credit_hold') && (bool) $owner->get('field_credit_hold')->value) {
        $flags[] = 'credit_hold';
      }
      if ($owner->hasField('field_do_not_schedule') && (bool) $owner->get('field_do_not_schedule')->value) {
        $flags[] = 'do_not_schedule';
      }
      if ($flags) {
        // No customer-facing indication — do not leak account status to the web.
        return new EligibilityResult(EligibilityResult::NOT_ELIGIBLE, $flags);
      }
    }

    $bundle = $this->resolveWorkOrderBundle($serviceTermId);
    [$windowStart, $windowEnd] = $this->serviceYearWindow($bundle, $serviceYear);

    // 3. Existing (non-Canceled) Work Order for this bundle/property in-window.
    if ($bundle) {
      $woStorage = $this->entityTypeManager->getStorage('work_order');
      $woIds = $woStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $bundle)
        ->condition('field_property', $propertyId)
        ->condition('created', $windowStart, '>=')
        ->condition('created', $windowEnd, '<=')
        ->sort('id', 'ASC')
        ->execute();
      foreach ($woStorage->loadMultiple($woIds) as $wo) {
        $statusTid = ($wo->hasField('field_status') && !$wo->get('field_status')->isEmpty())
          ? (int) $wo->get('field_status')->target_id : 0;
        // Any status except Canceled blocks (empty/unknown status blocks too).
        if (!in_array($statusTid, self::NON_BLOCKING_WO_STATUS_TIDS, TRUE)) {
          return new EligibilityResult(EligibilityResult::ALREADY_COVERED, [], (int) $wo->id(), NULL, 'work_order');
        }
      }
    }

    // 4. Contract coverage (may exist before any WO row).
    $contractStorage = $this->entityTypeManager->getStorage('contracts');
    $contractIds = $contractStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'residential')
      ->condition('field_property', $propertyId)
      ->condition('field_contract_year', $serviceYear)
      ->condition('field_contract_status', self::COVERED_CONTRACT_STATUS_TIDS, 'IN')
      ->sort('id', 'ASC')
      ->execute();
    if ($contractIds) {
      $sectionStorage = $this->entityTypeManager->getStorage('contract_sections');
      foreach ($contractIds as $contractId) {
        $sectionIds = $sectionStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('field_contract', $contractId)
          ->condition('field_service', $serviceTermId)
          ->condition('field_do_you_want', self::CONTRACT_WANTS, 'IN')
          ->sort('id', 'ASC')
          ->range(0, 1)
          ->execute();
        if ($sectionIds) {
          return new EligibilityResult(EligibilityResult::ALREADY_COVERED, [], NULL, (int) $contractId, 'contract');
        }
      }
    }

    // 5. Existing active service request for property + service + year.
    $notActive = $this->statusResolver->tids([
      ServiceRequestStatusResolver::REJECTED,
      ServiceRequestStatusResolver::DUPLICATE,
      ServiceRequestStatusResolver::CONVERTED,
    ]);
    $srStorage = $this->entityTypeManager->getStorage('service_request');
    $existingQuery = $srStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_property', $propertyId)
      ->condition('field_service', $serviceTermId)
      ->condition('field_service_year', $serviceYear)
      ->condition('field_request_status', $notActive, 'NOT IN')
      ->sort('id', 'ASC')
      ->range(0, 1);
    if ($excludeRequestId) {
      $existingQuery->condition('id', $excludeRequestId, '<>');
    }
    $existing = $existingQuery->execute();
    if ($existing) {
      return new EligibilityResult(EligibilityResult::DUPLICATE, [], NULL, NULL, NULL, (int) reset($existing));
    }

    // 6. Eligible. Attach disagreement flags (accept + flag, never swallow —
    //    P0.2/P1.4 asymmetric failure). Reaching here means no blocking WO and
    //    no covered-contract section were found for the year.
    $flags = [];
    if ($this->contractCompletedForYear($propertyId, $serviceTermId, $serviceYear)) {
      $flags[] = 'contract_completed_for_year';
    }
    if ($this->hasStandingShutDownFlag($propertyId)) {
      $flags[] = 'standing_flag_no_contract';
    }
    return new EligibilityResult(EligibilityResult::ELIGIBLE, $flags);
  }

  /**
   * TRUE when the property carries the standing shut-down-contract flag
   * (property_sprinkler_info.field_ss_shut_down_contract). A THIRD coverage
   * signal, independent of the WO and contract-section checks — and known to
   * drift (set years ago, never cleared). So at the eligible branch (no
   * current-year WO or covered section), a TRUE flag is a DISAGREEMENT: accept
   * the request and flag standing_flag_no_contract, never block (P1.4).
   */
  private function hasStandingShutDownFlag(int $propertyId): bool {
    $ids = $this->entityTypeManager->getStorage('property_sprinkler_info')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_property', $propertyId)
      ->condition('field_ss_shut_down_contract', 1)
      ->range(0, 1)
      ->execute();
    return !empty($ids);
  }

  /**
   * TRUE when a current-year residential contract at "Completed for the Year"
   * (1127) holds a wanted section for this service. A disagreement signal: the
   * request is still eligible, but flagged for an office check (P0.2).
   */
  private function contractCompletedForYear(int $propertyId, int $serviceTermId, int $serviceYear): bool {
    $contractIds = $this->entityTypeManager->getStorage('contracts')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'residential')
      ->condition('field_property', $propertyId)
      ->condition('field_contract_year', $serviceYear)
      ->condition('field_contract_status', self::CONTRACT_COMPLETED_FOR_YEAR_TID)
      ->execute();
    if (!$contractIds) {
      return FALSE;
    }
    $sectionStorage = $this->entityTypeManager->getStorage('contract_sections');
    foreach ($contractIds as $contractId) {
      $sectionIds = $sectionStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_contract', $contractId)
        ->condition('field_service', $serviceTermId)
        ->condition('field_do_you_want', self::CONTRACT_WANTS, 'IN')
        ->range(0, 1)
        ->execute();
      if ($sectionIds) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Work Order bundle machine name for a services term (field_service_bundle).
   */
  public function resolveWorkOrderBundle(int $serviceTermId): ?string {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($serviceTermId);
    if ($term && $term->hasField('field_service_bundle') && !$term->get('field_service_bundle')->isEmpty()) {
      return (string) $term->get('field_service_bundle')->value;
    }
    return NULL;
  }

  /**
   * Service-year window [startTs, endTs] from config (site tz — never
   * FROM_UNIXTIME / MySQL session tz). Falls back to the calendar year.
   */
  private function serviceYearWindow(?string $bundle, int $serviceYear): array {
    $settings = $this->configFactory->get('bos_service_request.settings');
    $start = $bundle ? $settings->get("bundles.$bundle.service_year_start") : NULL;
    $end = $bundle ? $settings->get("bundles.$bundle.service_year_end") : NULL;
    $tz = new \DateTimeZone(date_default_timezone_get());
    $startDt = $start
      ? new DrupalDateTime($start . ' 00:00:00', $tz)
      : new DrupalDateTime($serviceYear . '-01-01 00:00:00', $tz);
    $endDt = $end
      ? new DrupalDateTime($end . ' 23:59:59', $tz)
      : new DrupalDateTime($serviceYear . '-12-31 23:59:59', $tz);
    return [$startDt->getTimestamp(), $endDt->getTimestamp()];
  }

}
