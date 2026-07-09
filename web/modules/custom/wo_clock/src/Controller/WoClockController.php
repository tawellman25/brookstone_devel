<?php

declare(strict_types=1);

namespace Drupal\wo_clock\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\wo_clock\Service\WoClockService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * AJAX endpoints for the clock-in/out UX.
 *
 * All actions run as the current user. Clock-in creates entries only for the
 * current user (uid is taken from the session, never the request body), so a
 * crafted POST cannot clock in on behalf of someone else. Clock-out/close
 * verify ownership unless the caller holds 'administer eck entities' (the
 * office/admin correction bypass).
 */
final class WoClockController extends ControllerBase {

  public function __construct(
    private readonly WoClockService $clock,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('wo_clock.clock_service'));
  }

  /**
   * POST /clock/in/{wo_id}
   */
  public function clockInAction(int $wo_id, Request $request): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    $body = $this->body($request);
    [$lat, $lon] = $this->latLon($body);
    $resolved = !empty($body['resolved_entries']);

    $openElsewhere = $this->clock->getOpenEntriesForUser($uid, $wo_id);
    if ($openElsewhere && !$resolved) {
      return new JsonResponse([
        'status' => 'intervention_required',
        'open_entries' => array_map([$this, 'openEntryRow'], $openElsewhere),
      ]);
    }

    $note = NULL;
    if ($resolved) {
      $tapAt = isset($body['tap_time']) ? (string) $body['tap_time'] : date('H:i:s');
      $note = sprintf(
        '[Auto-created after resolving %d open entries. Original clock-in tap at %s, entry created at %s after resolution.]',
        (int) ($body['resolved_count'] ?? count($openElsewhere)),
        $tapAt,
        date('H:i:s')
      );
    }

    try {
      $entry = $this->clock->clockIn($uid, $wo_id, $lat, $lon, $note);
    }
    catch (\Throwable $e) {
      return $this->error($e->getMessage());
    }
    return new JsonResponse(['status' => 'clocked_in', 'entry' => $this->entryData($entry)]);
  }

  /**
   * POST /clock/out/{entry_id}
   */
  public function clockOutAction(int $entry_id, Request $request): JsonResponse {
    $entry = $this->loadOwned($entry_id);
    if ($entry instanceof JsonResponse) {
      return $entry;
    }
    $body = $this->body($request);
    [$lat, $lon] = $this->latLon($body);
    $override = !empty($body['override']);

    // Over the single-entry cap and not yet confirmed → ask the crew to confirm
    // the long entry rather than dead-ending (the override box isn't reachable
    // from the button).
    if (!$override) {
      $exceed = $this->clock->capExceedanceHours($entry);
      if ($exceed !== NULL) {
        $h = rtrim(rtrim(number_format($exceed['hours'], 2), '0'), '.');
        $cap = rtrim(rtrim(number_format($exceed['cap'], 2), '0'), '.');
        return new JsonResponse([
          'status' => 'confirm_long',
          'hours' => $exceed['hours'],
          'cap' => $exceed['cap'],
          'message' => "This is a long entry — {$h} hours, over the {$cap}-hour limit for this service. "
            . "If that's correct, tap OK to clock out anyway. Otherwise tap Cancel and have the office fix the time.",
        ]);
      }
    }

    try {
      $entry = $this->clock->clockOut($entry_id, $lat, $lon, $override);
    }
    catch (\Throwable $e) {
      // Phase 1 guard (or any) fired — no data change beyond the rejected save.
      return $this->error($e->getMessage());
    }
    return new JsonResponse(['status' => 'clocked_out', 'entry' => $this->entryData($entry)]);
  }

  /**
   * POST /clock/close/{entry_id}
   */
  public function closeEntryAction(int $entry_id, Request $request): JsonResponse {
    $entry = $this->loadOwned($entry_id);
    if ($entry instanceof JsonResponse) {
      return $entry;
    }
    try {
      $this->clock->closeEntry($entry_id, NULL, TRUE);
    }
    catch (\Throwable $e) {
      return $this->error($e->getMessage());
    }
    return new JsonResponse([
      'status' => 'closed',
      'remaining_open' => array_map([$this, 'openEntryRow'], $this->clock->getOpenEntriesForUser((int) $this->currentUser()->id())),
    ]);
  }

  /**
   * POST /clock/close/{entry_id}/time
   *
   * body: end_time = a datetime-local value "Y-m-dTH:i" (site tz). A bare "HH:MM"
   * is still accepted from older cached clients and resolved against the entry's
   * start / today, as before.
   */
  public function closeEntryWithTimeAction(int $entry_id, Request $request): JsonResponse {
    $entry = $this->loadOwned($entry_id);
    if ($entry instanceof JsonResponse) {
      return $entry;
    }
    $body = $this->body($request);
    $raw = isset($body['end_time']) ? trim((string) $body['end_time']) : '';
    $ts = $this->resolveEndInput($entry, $raw);
    if ($ts === NULL) {
      return $this->error('Invalid end time — pick a date and time at or after the clock-in.');
    }
    try {
      $this->clock->closeEntry($entry_id, $ts, TRUE);
    }
    catch (\Throwable $e) {
      return $this->error($e->getMessage());
    }
    return new JsonResponse([
      'status' => 'closed',
      'remaining_open' => array_map([$this, 'openEntryRow'], $this->clock->getOpenEntriesForUser((int) $this->currentUser()->id())),
    ]);
  }

  // -- helpers ---------------------------------------------------------------

  private function body(Request $request): array {
    $raw = $request->getContent();
    if ($raw === '') {
      return $request->request->all();
    }
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  private function latLon(array $body): array {
    $lat = isset($body['lat']) && is_numeric($body['lat']) ? (float) $body['lat'] : NULL;
    $lon = isset($body['lon']) && is_numeric($body['lon']) ? (float) $body['lon'] : NULL;
    return [$lat, $lon];
  }

  /**
   * Load an entry, enforcing ownership (or admin bypass). Returns a 403
   * JsonResponse when the current user may not act on it.
   *
   * @return \Drupal\Core\Entity\EntityInterface|\Symfony\Component\HttpFoundation\JsonResponse
   */
  private function loadOwned(int $entry_id) {
    $entry = $this->entityTypeManager()->getStorage('wo_time_clock')->load($entry_id);
    if (!$entry) {
      return $this->error('Entry not found.', 404);
    }
    $uid = (int) $this->currentUser()->id();
    $owner = $entry->hasField('field_teammate') && !$entry->get('field_teammate')->isEmpty()
      ? (int) $entry->get('field_teammate')->target_id : (int) $entry->getOwnerId();
    if ($owner !== $uid && !$this->currentUser()->hasPermission('administer eck entities')) {
      return $this->error('You can only manage your own time entries.', 403);
    }
    return $entry;
  }

  /**
   * Resolve an HH:MM end time to a Unix timestamp, guarding end-before-start.
   */
  /**
   * Resolve the submitted end-time value to a UTC timestamp.
   *
   * Accepts a full datetime-local ("Y-m-dTH:i", ":s" optional, space tolerated),
   * interpreted in site tz — this is what the picker now sends, so a clock-in
   * closed on a later day records the correct date. Falls back to a bare "HH:MM"
   * (older cached client) resolved against today / the start day. Returns NULL on
   * a bad value or an end that precedes the entry's start.
   */
  private function resolveEndInput(EntityInterface $entry, string $raw): ?int {
    $tz = new \DateTimeZone(date_default_timezone_get());
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ]([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $raw, $m)) {
      try {
        $dt = new \DateTime($m[1] . ' ' . $m[2] . ':' . $m[3] . ':00', $tz);
      }
      catch (\Throwable $e) {
        return NULL;
      }
      return $dt->getTimestamp() >= $this->entryStartTs($entry) ? $dt->getTimestamp() : NULL;
    }
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $raw, $m)) {
      return $this->resolveEndTimestamp($entry, (int) $m[1], (int) $m[2]);
    }
    return NULL;
  }

  /**
   * The entry's start as a UTC timestamp (0 if unset).
   */
  private function entryStartTs(EntityInterface $entry): int {
    $startVal = (string) ($entry->get('field_start_time')->value ?? '');
    try {
      return $startVal ? (new \DateTime($startVal, new \DateTimeZone('UTC')))->getTimestamp() : 0;
    }
    catch (\Throwable $e) {
      return 0;
    }
  }

  private function resolveEndTimestamp(EntityInterface $entry, int $hh, int $mm): ?int {
    $tz = new \DateTimeZone(date_default_timezone_get());
    $startVal = (string) ($entry->get('field_start_time')->value ?? '');
    $start = $startVal ? (new \DateTime($startVal, new \DateTimeZone('UTC')))->setTimezone($tz) : new \DateTime('now', $tz);

    // Resolve against today first; if that lands before the start (entry began
    // on an earlier day), resolve against the entry's start date instead.
    $today = new \DateTime('now', $tz);
    $today->setTime($hh, $mm, 0);
    if ($today->getTimestamp() >= $start->getTimestamp()) {
      return $today->getTimestamp();
    }
    $onStartDay = clone $start;
    $onStartDay->setTime($hh, $mm, 0);
    if ($onStartDay->getTimestamp() >= $start->getTimestamp()) {
      return $onStartDay->getTimestamp();
    }
    return NULL;
  }

  private function entryData(EntityInterface $entry): array {
    return [
      'id' => (int) $entry->id(),
      'wo_id' => $entry->hasField('field_work_order') && !$entry->get('field_work_order')->isEmpty() ? (int) $entry->get('field_work_order')->target_id : 0,
      'start_time' => (string) ($entry->get('field_start_time')->value ?? ''),
      'end_time' => (string) ($entry->get('field_end_time')->value ?? ''),
    ];
  }

  private function openEntryRow(EntityInterface $entry): array {
    $wo = $entry->hasField('field_work_order') && !$entry->get('field_work_order')->isEmpty() ? $entry->get('field_work_order')->entity : NULL;
    $prop = ($wo && $wo->hasField('field_property') && !$wo->get('field_property')->isEmpty()) ? $wo->get('field_property')->entity : NULL;
    $start = (string) ($entry->get('field_start_time')->value ?? '');
    return [
      'entry_id' => (int) $entry->id(),
      'wo_id' => $wo ? (int) $wo->id() : 0,
      'wo_url' => $wo ? $wo->toUrl()->toString() : '',
      'property' => $prop ? $prop->label() : ($wo ? $wo->label() : 'Work Order'),
      'start_us' => _wo_clock_fmt_us($start),
      'start_local' => _wo_clock_fmt_local($start),
      'now_local' => _wo_clock_now_local(),
      'ago' => _wo_clock_ago($start),
    ];
  }

  private function error(string $message, int $status = 200): JsonResponse {
    // Status 200 by default so the frontend reads {status:error} uniformly;
    // hard HTTP codes (403/404) are used for auth/existence failures.
    return new JsonResponse(['status' => 'error', 'message' => $message], $status);
  }

}
