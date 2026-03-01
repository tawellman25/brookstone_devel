<?php

declare(strict_types=1);

namespace Drupal\estimate\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds and displays non-blocking intake warnings for Estimate Requests.
 *
 * IMPORTANT:
 * - Never blocks saving.
 * - Never auto-changes status.
 * - Skips CLI (drush/cron/imports) to avoid noise.
 */
final class EstimateRequestWarningBuilder {

  public function __construct(
    private readonly MessengerInterface $messenger,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Apply soft intake warnings for an estimate_request entity.
   */
  public function apply(EntityInterface $req): void {
    if ($req->getEntityTypeId() !== 'estimate_request') {
      return;
    }

    // Avoid spamming during CLI runs (drush, cron, imports).
    if (PHP_SAPI === 'cli') {
      return;
    }

    // Ensure we are in a request context.
    if (!$this->requestStack->getCurrentRequest()) {
      return;
    }

    // Warn if no contact info at all.
    $has_contact_entity = $req->hasField('field_contact') && !$req->get('field_contact')->isEmpty();
    $has_name = $req->hasField('field_requestor_name') && trim((string) ($req->get('field_requestor_name')->value ?? '')) !== '';
    $has_phone = $req->hasField('field_requestor_phone') && trim((string) ($req->get('field_requestor_phone')->value ?? '')) !== '';
    $has_email = $req->hasField('field_requestor_email') && trim((string) ($req->get('field_requestor_email')->value ?? '')) !== '';

    if (!$has_contact_entity && !$has_name && !$has_phone && !$has_email) {
      $this->messenger->addWarning('No contact information has been provided (no Contact linked and no Requestor Name/Phone/Email entered).');
    }

    // Helpful warnings (non-blocking).
    if ($req->hasField('field_property') && $req->get('field_property')->isEmpty()) {
      $this->messenger->addWarning('Property is not linked yet. This can be completed after intake, but should be confirmed before estimating and before converting an accepted Estimate into a Work Order.');
    }

    if ($req->hasField('field_owner') && $req->get('field_owner')->isEmpty()) {
      $this->messenger->addWarning('Owner is not confirmed yet. Owner is the point-in-time billing/authorization authority for this request.');
    }

    if ($req->hasField('field_service') && $req->get('field_service')->isEmpty()) {
      $this->messenger->addWarning('No Service Estimate(s) Requested have been selected yet. Select Service(s) before generating Estimates from this request.');
    }
  }

}
