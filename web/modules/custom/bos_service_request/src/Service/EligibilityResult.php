<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Service;

/**
 * Immutable result of ServiceRequestEligibility::evaluate().
 *
 * outcome:
 *  - eligible       → may be created New and later converted.
 *  - not_eligible   → recorded + flagged, Needs Review, never auto-convertible.
 *  - already_covered→ an existing WO or contract already covers this property/year.
 *  - duplicate      → an active service request already exists.
 */
final class EligibilityResult {

  public const ELIGIBLE = 'eligible';
  public const NOT_ELIGIBLE = 'not_eligible';
  public const ALREADY_COVERED = 'already_covered';
  public const DUPLICATE = 'duplicate';

  /**
   * @param string $outcome One of the class constants.
   * @param string[] $flags Machine flags (no_services, credit_hold, …).
   * @param int|null $existingWorkOrderId WO that already covers, if any.
   * @param int|null $existingContractId Contract that already covers, if any.
   * @param string|null $coveragePath 'work_order' | 'contract' when already_covered.
   * @param int|null $duplicateRequestId Prior active request when duplicate.
   */
  public function __construct(
    public readonly string $outcome,
    public readonly array $flags = [],
    public readonly ?int $existingWorkOrderId = NULL,
    public readonly ?int $existingContractId = NULL,
    public readonly ?string $coveragePath = NULL,
    public readonly ?int $duplicateRequestId = NULL,
  ) {}

  public function isEligible(): bool {
    return $this->outcome === self::ELIGIBLE;
  }

}
