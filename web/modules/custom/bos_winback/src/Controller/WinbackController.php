<?php

declare(strict_types=1);

namespace Drupal\bos_winback\Controller;

use Drupal\bos_service_request\Service\ServiceRequestConverter;
use Drupal\bos_service_request\Service\ServiceRequestStatusResolver;
use Drupal\bos_winback\Service\WinbackListService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Winterize win-back call list + call-outcome recorder.
 */
final class WinbackController extends ControllerBase {

  public function __construct(
    private readonly WinbackListService $winback,
    private readonly ServiceRequestConverter $converter,
    private readonly ServiceRequestStatusResolver $statusResolver,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bos_winback.list'),
      $container->get('bos_service_request.converter'),
      $container->get('bos_service_request.status_resolver'),
    );
  }

  /**
   * The call list page.
   */
  public function list(Request $request): array {
    $lookback = $this->winback->clampLookback((int) $request->query->get('years', 1));
    $rows = $this->winback->getRows($lookback);
    $target_year = $this->winback->targetYear();

    $no_phone = count(array_filter($rows, fn($r) => $r['phone'] === ''));
    $canceled = count(array_filter($rows, fn($r) => $r['was_canceled']));
    $revenue = array_sum(array_map(fn($r) => (float) $r['last_total'], $rows));
    $worked = count(array_filter($rows, fn($r) => !empty($r['state'])));
    $summary = $this->winback->getSummary($lookback);

    return [
      '#theme' => 'bos_winback_list',
      '#rows' => $rows,
      '#declined' => $this->winback->getDeclined(),
      '#reasons' => WinbackListService::DECLINE_REASONS,
      '#target_year' => $target_year,
      '#lookback' => $lookback,
      '#earliest_year' => $target_year - $lookback,
      '#stats' => [
        'total' => count($rows),
        'no_phone' => $no_phone,
        'canceled' => $canceled,
        'worked' => $worked,
        'revenue' => number_format($revenue, 2),
        'won_back' => $summary['won_back'],
        'came_back' => $summary['came_back'],
        'carried' => $summary['carried'],
        'batch_date' => $summary['batch_date'],
        'source_total' => $summary['source_total'],
        'pct' => $summary['pct'],
      ],
      '#attached' => [
        'library' => ['bos_winback/winback'],
        'drupalSettings' => [
          'bosWinback' => [
            'markUrlBase' => '/admin/office/winterize/win-back/mark/',
            'createUrlBase' => '/admin/office/winterize/win-back/create/',
          ],
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Record (or clear) a call outcome for one property. AJAX endpoint.
   */
  public function mark(Request $request, int $property): JsonResponse {
    $outcome = (string) $request->request->get('outcome', '');
    $reason = (string) $request->request->get('reason', '');
    $note = (string) $request->request->get('note', '');
    $by = (string) $this->currentUser()->getDisplayName();

    if ($outcome === 'clear') {
      $this->winback->clearState($property);
      return new JsonResponse(['status' => 'ok', 'cleared' => TRUE]);
    }

    try {
      $rec = $this->winback->mark($property, $outcome, $by, $reason, $note);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
    }

    return new JsonResponse([
      'status' => 'ok',
      'outcome' => $outcome,
      'by' => $by,
      'time' => date('m/d/Y', $rec['time_ts']),
      // Declined removes the customer from the list.
      'suppress' => $outcome === 'declined',
    ]);
  }

  /**
   * Create a winterizing booking from the win-back list. AJAX endpoint.
   *
   * BOS-aligned: builds a service_request (the same entity the /winterize form
   * uses) stamped with the reactivation campaign + phone/reactivation source,
   * authored by the caller, then converts it to a Work Order via the existing
   * converter — so win-back bookings land in the SAME campaign field and the
   * SAME Report tab as web bookings (no parallel attribution system). First-
   * touch is preserved automatically: a property that already has a current WO
   * is off this list AND the converter's eligibility gate refuses a duplicate.
   */
  public function createBooking(Request $request, int $property): JsonResponse {
    $enroll = (bool) $request->request->get('enroll', 0);
    $account = $this->currentUser();

    // Winterizing service term (Services vocab, field_service_bundle mapping).
    $ids = $this->entityTypeManager()->getStorage('taxonomy_term')->getQuery()
      ->condition('field_service_bundle', 'sprinkler_winterizing')
      ->accessCheck(FALSE)->range(0, 1)->execute();
    $serviceTermId = $ids ? (int) reset($ids) : 0;
    if (!$serviceTermId) {
      return new JsonResponse(['status' => 'error', 'message' => 'Winterizing service term not found.'], 500);
    }

    $year = (int) date('Y');
    // Campaign code: admin-editable prefix + the current two-digit year, RESOLVED
    // ONCE and stored on the record. Never recompute at read time — a booking made
    // in 2026 must still read react26 in January 2027. (Calendar year is safe for
    // winterization; a snow-season code would need a July-rolling season-year.)
    $prefix = (string) ($this->config('bos_winback.settings')->get('campaign_prefix') ?: 'react');
    $prefix = preg_replace('/[^a-z0-9_-]/i', '', $prefix) ?: 'react';
    $campaign = $prefix . substr((string) $year, -2);

    $contact = $this->winback->contactForProperty($property);

    // Build the service_request (caller = author → free per-caller productivity).
    $values = [
      'type' => 'sprinkler_winterizing',
      'uid' => (int) $account->id(),
      'field_property' => $property,
      'field_service' => $serviceTermId,
      'field_service_year' => $year,
      'field_request_status' => $this->statusResolver->tid(ServiceRequestStatusResolver::VERIFIED),
      'field_source' => 'reactivation',
      'field_campaign' => $campaign,
      'field_submitted_name' => $contact ? $contact->label() : '',
      'field_office_notes' => 'Created from the Win-Back call list.',
      'field_wants_recurring' => $enroll,
    ];
    $srStorage = $this->entityTypeManager()->getStorage('service_request');
    $sr = $srStorage->create($values);
    if ($contact && $sr->hasField('field_contact')) {
      $sr->set('field_contact', (int) $contact->id());
    }
    $sr->save();

    // Convert to a Work Order (same path web bookings use).
    $result = $this->converter->convert($sr, $account);
    $st = $result['status'] ?? 'error';
    if ($st !== 'converted' && $st !== 'already_converted') {
      // Eligibility gate / error — the property likely already has a booking, or
      // is on hold. Surface it; the service_request stays for office review.
      return new JsonResponse([
        'status' => 'blocked',
        'message' => $result['message'] ?? ('Could not create the work order (' . $st . ').'),
      ]);
    }
    $woId = (int) ($result['work_order_id'] ?? 0);

    // Auto-enroll on the call (opt-in on the Contact) — never flip an opt-out.
    $enrolled = NULL;
    if ($enroll) {
      $enrolled = $contact ? $this->enrollContact($contact, (int) $account->id()) : 'no_contact';
    }

    return new JsonResponse([
      'status' => 'ok',
      'work_order_id' => $woId,
      'wo_url' => $woId ? Url::fromRoute('entity.work_order.edit_form', ['work_order' => $woId])->toString() : '',
      'campaign' => $campaign,
      'enrolled' => $enrolled,
    ]);
  }

  /**
   * Set the service-email opt-in on a contact from a phone call. Returns
   * 'enrolled', or 'opted_out' if an explicit opt-out is left untouched.
   * bos_consent_log records the actor/timestamp/source through the same save.
   */
  private function enrollContact($contact, int $actorUid): string {
    if ($contact->hasField('field_opt_in_service_email')
      && !$contact->get('field_opt_in_service_email')->isEmpty()
      && (int) $contact->get('field_opt_in_service_email')->value === 0) {
      $this->getLogger('bos_winback')->notice('Win-back enroll skipped: contact @c already opted out.', ['@c' => $contact->id()]);
      return 'opted_out';
    }
    $contact->set('field_opt_in_service_email', TRUE);
    $contact->set('field_consent_updated', (new DrupalDateTime('now', 'UTC'))->format('Y-m-d\TH:i:s'));
    $contact->set('field_consent_source', 'phone');
    // Attribution hints for bos_consent_log (no IP — this is a phone call).
    $contact->_consent_source = 'phone';
    $contact->_consent_actor = $actorUid;
    $contact->_consent_note = 'win-back phone enrollment';
    $contact->save();
    return 'enrolled';
  }

}
