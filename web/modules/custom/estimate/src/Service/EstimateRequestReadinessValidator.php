<?php

declare(strict_types=1);

namespace Drupal\estimate\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Config-driven readiness validation for Estimate Requests.
 *
 * Hard validation ONLY when transitioning to configured "Ready to Estimate" tid.
 *
 * IMPORTANT:
 * - Optional: does nothing unless estimate.settings includes:
 *   estimate_request_ready_status_tid
 * - Never blocks intake unless you explicitly configure that tid.
 */
final class EstimateRequestReadinessValidator {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function validate(EntityInterface $entity, ConstraintViolationListInterface $violations): void {
    if ($entity->getEntityTypeId() !== 'estimate_request') {
      return;
    }

    $ready_tid = (int) $this->configFactory->get('estimate.settings')->get('estimate_request_ready_status_tid');
    if ($ready_tid <= 0) {
      // Not configured => no blocking validation.
      return;
    }

    $status_field = $this->getStatusFieldName($entity);
    $new_status_tid = $this->getStatusTid($entity);
    if ($new_status_tid <= 0) {
      return;
    }

    // Only enforce when transitioning to Ready.
    if ($new_status_tid !== $ready_tid) {
      return;
    }

    // If already Ready and staying Ready, do nothing.
    $old_status_tid = 0;
    if (isset($entity->original) && $entity->original instanceof EntityInterface) {
      $old_status_tid = $this->getStatusTid($entity->original);
    }
    if ($old_status_tid === $ready_tid) {
      return;
    }

    $missing = $this->missingReadyRequirements($entity);
    if (empty($missing)) {
      return;
    }

    $msg = 'Cannot mark Ready to Estimate until the following are set: ' . implode(', ', $missing) . '.';

    $violations->add(new ConstraintViolation(
      $msg,
      $msg,
      [],
      $entity,
      $status_field,
      (string) $new_status_tid
    ));
  }

  private function getStatusFieldName(EntityInterface $req): string {
    if ($req->hasField('field_status')) {
      return 'field_status';
    }

    foreach (['field_estimate_request_status', 'field_request_status', 'status'] as $candidate) {
      if ($req->hasField($candidate)) {
        return $candidate;
      }
    }

    return 'field_status';
  }

  private function getStatusTid(EntityInterface $req): int {
    $field = $this->getStatusFieldName($req);
    if (!$req->hasField($field) || $req->get($field)->isEmpty()) {
      return 0;
    }
    return (int) ($req->get($field)->target_id ?? 0);
  }

  /**
   * Hard readiness requirements for "Ready to Estimate".
   *
   * Returns a list of human-readable missing labels.
   *
   * IMPORTANT:
   * This is a readiness gate for estimate generation, not intake.
   */
  private function missingReadyRequirements(EntityInterface $req): array {
    $missing = [];

    // Property required at readiness.
    if ($req->hasField('field_property') && $req->get('field_property')->isEmpty()) {
      $missing[] = 'Property';
    }

    // Owner snapshot required at readiness (point-in-time).
    if ($req->hasField('field_owner') && $req->get('field_owner')->isEmpty()) {
      $missing[] = 'Owner';
    }

    // At least one service required to generate estimates deterministically.
    if ($req->hasField('field_service') && $req->get('field_service')->isEmpty()) {
      $missing[] = 'Service Estimate(s) Requested';
    }

    // Require either a linked Contact OR at least one requestor detail.
    $has_contact_entity = $req->hasField('field_contact') && !$req->get('field_contact')->isEmpty();
    $has_name = $req->hasField('field_requestor_name') && trim((string) ($req->get('field_requestor_name')->value ?? '')) !== '';
    $has_phone = $req->hasField('field_requestor_phone') && trim((string) ($req->get('field_requestor_phone')->value ?? '')) !== '';
    $has_email = $req->hasField('field_requestor_email') && trim((string) ($req->get('field_requestor_email')->value ?? '')) !== '';

    if (!$has_contact_entity && !$has_name && !$has_phone && !$has_email) {
      $missing[] = 'Contact or Requestor info (Name/Phone/Email)';
    }

    return $missing;
  }

}
