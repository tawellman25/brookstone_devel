<?php

declare(strict_types=1);

namespace Drupal\wo_clock\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Clock-in/out domain service for wo_time_clock entries.
 *
 * Owns entry creation/closure, open-entry lookups, silent GPS storage, and
 * the Haversine distance-from-property calculation. Deliberately transport-
 * agnostic: the controller (AJAX) and any future caller drive it the same way.
 *
 * Datetime storage: field_start_time / field_end_time are datetime fields
 * stored as UTC 'Y-m-d\TH:i:s' (the BOS convention — see wo_sign_off).
 *
 * GPS is stored as geofield WKT "POINT (lon lat)". All GPS is silent-optional:
 * a null lat/lon simply leaves the location field empty; nothing blocks.
 */
final class WoClockService {

  /**
   * Datetime field storage format (UTC).
   */
  private const DT = 'Y-m-d\TH:i:s';

  /**
   * WO statuses where the guard-bypass flag is applied for retroactive close.
   * Invoiced (1281) / Paid (1504) — matches wo_total_time Phase 1 Guard 4.
   */
  private const LOCKED_STATUSES = [1281, 1504];

  /**
   * Earth radius in feet (6371 km).
   */
  private const EARTH_RADIUS_FEET = 20902231.0;

  /**
   * Structured note formats (sprintf). Timestamps are site-tz MM/DD/YYYY h:i AM/PM.
   * Notes accumulate newline-separated across a start and a later end.
   */
  public const NOTE_BUTTON_START = '[Start: Button %s]';
  public const NOTE_BUTTON_END = '[End: Button %s]';
  public const NOTE_INTERVENTION_END = '[End: Intervention %s by %s]';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Now, as a UTC datetime storage string.
   */
  private function nowUtc(): string {
    return gmdate(self::DT, $this->time->getRequestTime());
  }

  /**
   * A UTC datetime storage string for an arbitrary timestamp.
   */
  private function tsUtc(int $timestamp): string {
    return gmdate(self::DT, $timestamp);
  }

  private function tcStorage() {
    return $this->entityTypeManager->getStorage('wo_time_clock');
  }

  /**
   * Open wo_time_clock entries for a user (field_end_time IS NULL).
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Oldest-first (by field_start_time).
   */
  public function getOpenEntriesForUser(int $uid, ?int $excludeWoId = NULL): array {
    if ($uid <= 0) {
      return [];
    }
    // field_teammate is the canonical "whose time". Owner uid is the fallback
    // for legacy entries where field_teammate was never synced; query both and
    // merge so neither convention is missed.
    $ids = [];
    foreach (['field_teammate', 'uid'] as $field) {
      $q = $this->tcStorage()->getQuery()
        ->accessCheck(FALSE)
        ->condition($field, $uid)
        ->notExists('field_end_time')
        ->sort('field_start_time', 'ASC');
      if ($excludeWoId !== NULL) {
        $q->condition('field_work_order', $excludeWoId, '<>');
      }
      foreach ($q->execute() as $id) {
        $ids[$id] = TRUE;
      }
    }
    if (!$ids) {
      return [];
    }
    $entries = $this->tcStorage()->loadMultiple(array_keys($ids));
    // Re-sort merged set oldest-first.
    uasort($entries, function (EntityInterface $a, EntityInterface $b) {
      return strcmp((string) ($a->get('field_start_time')->value ?? ''), (string) ($b->get('field_start_time')->value ?? ''));
    });
    return array_values($entries);
  }

  /**
   * The user's current open entry on a specific WO, or NULL.
   */
  public function getCurrentEntryOnWo(int $uid, int $woId): ?EntityInterface {
    if ($uid <= 0 || $woId <= 0) {
      return NULL;
    }
    foreach (['field_teammate', 'uid'] as $field) {
      $ids = $this->tcStorage()->getQuery()
        ->accessCheck(FALSE)
        ->condition($field, $uid)
        ->condition('field_work_order', $woId)
        ->notExists('field_end_time')
        ->sort('field_start_time', 'DESC')
        ->range(0, 1)
        ->execute();
      if ($ids) {
        return $this->tcStorage()->load(reset($ids));
      }
    }
    return NULL;
  }

  /**
   * Build an OPEN wo_time_clock entry (unsaved), guaranteeing field_end_time
   * is cleared.
   *
   * ⚠ field_end_time carries an instance default_value of "now", so a plain
   * ->create() AUTO-CLOSES the entry (a subtle, data-corrupting trap — an entry
   * meant to be open is silently born closed, and open-entry lookups + the
   * sign-off guards then never see it). Any code that programmatically creates
   * an intended-open entry MUST clear field_end_time. Route all such creation
   * through this method so the guarantee lives in exactly one place.
   *
   * Returns the entity UNSAVED so callers can add GPS / notes before saving.
   *
   * @param array $extra
   *   Additional field values to merge into create() (e.g. a note).
   */
  public function createOpenEntry(int $uid, int $woId, array $extra = []): EntityInterface {
    $entry = $this->tcStorage()->create([
      'type' => 'entry',
      'field_teammate' => $uid,
      'field_work_order' => $woId,
      'field_start_time' => $this->nowUtc(),
    ] + $extra);
    // The guarantee: never inherit the "now" default on an open entry.
    $entry->set('field_end_time', NULL);
    // Clear the field_notes default of "Manually Entered" — this is a BUTTON
    // clock-in, not a manual entry; the structured note below is the real
    // attribution. (This default is the source of the "Manually Entered"
    // mislabel — same default-value trap as field_end_time's "now".)
    $entry->set('field_notes', NULL);
    // Owner mirrors the teammate so downstream owner-based reads agree.
    if ($entry->hasField('uid')) {
      $entry->set('uid', $uid);
    }
    // Origin attribution: a button clock-in, with a structured start note.
    $entry->set('field_source', 'wo_clock_button');
    $this->appendNote($entry, sprintf(self::NOTE_BUTTON_START, $this->usDisplay($this->time->getRequestTime())));
    $entry->_wo_clock_write = TRUE;
    return $entry;
  }

  /**
   * Create + save a new clock-in entry (open, via createOpenEntry()).
   */
  public function clockIn(int $uid, int $woId, ?float $lat = NULL, ?float $lon = NULL, ?string $noteContext = NULL): EntityInterface {
    $entry = $this->createOpenEntry($uid, $woId);
    if ($lat !== NULL && $lon !== NULL) {
      $entry->set('field_clock_in_location', $this->wkt($lat, $lon));
    }
    if ($noteContext !== NULL && $noteContext !== '') {
      $this->appendNote($entry, $noteContext);
    }
    $entry->save();
    return $entry;
  }

  /**
   * Set field_end_time = now on an entry, capturing clock-out GPS.
   *
   * Respects Phase 1 guards — a guard throw propagates to the caller.
   */
  public function clockOut(int $entryId, ?float $lat = NULL, ?float $lon = NULL, bool $override = FALSE): EntityInterface {
    $entry = $this->loadEntry($entryId);
    $entry->set('field_end_time', $this->nowUtc());
    if ($lat !== NULL && $lon !== NULL) {
      $entry->set('field_clock_out_location', $this->wkt($lat, $lon));
    }
    // Crew confirmed a genuinely-long entry — set the override so Guard 6
    // accepts it (and stamps its own audit note attributing the confirmer).
    if ($override && $entry->hasField('field_time_limit_override')) {
      $entry->set('field_time_limit_override', TRUE);
    }
    // field_source stays as set at clock-in; record the button clock-out.
    $this->appendNote($entry, sprintf(self::NOTE_BUTTON_END, $this->usDisplay($this->time->getRequestTime())));
    $entry->_wo_clock_write = TRUE;
    $entry->save();
    return $entry;
  }

  /**
   * If clocking out NOW would push this entry over its bundle's single-entry
   * cap (wo_total_time Guard 6), return ['hours' => float, 'cap' => float];
   * otherwise NULL. Lets the button ask the crew to confirm a long entry
   * instead of dead-ending, since the override checkbox isn't on the button.
   */
  public function capExceedanceHours(EntityInterface $entry): ?array {
    if ($entry->get('field_start_time')->isEmpty()) {
      return NULL;
    }
    try {
      $start = new \DateTime($entry->get('field_start_time')->value, new \DateTimeZone('UTC'));
    }
    catch (\Throwable $e) {
      return NULL;
    }
    $hours = ($this->time->getRequestTime() - $start->getTimestamp()) / 3600;
    if ($hours <= 0) {
      return NULL;
    }
    $wo = $entry->get('field_work_order')->entity;
    if (!$wo) {
      return NULL;
    }
    $cap = function_exists('_wo_total_time_get_max_entry_hours')
      ? (float) _wo_total_time_get_max_entry_hours($wo->bundle())
      : 4.0;
    return $hours > $cap ? ['hours' => round($hours, 2), 'cap' => $cap] : NULL;
  }

  /**
   * Close an entry retroactively (alert-region / modal intervention).
   *
   * @param int|null $endTimestamp
   *   Unix timestamp for the close, or NULL for now.
   * @param bool $auditNote
   *   Whether to prepend a "[Closed via intervention …]" audit note.
   */
  public function closeEntry(int $entryId, ?int $endTimestamp = NULL, bool $auditNote = TRUE): EntityInterface {
    $entry = $this->loadEntry($entryId);
    $end = $endTimestamp !== NULL ? $this->tsUtc($endTimestamp) : $this->nowUtc();
    $entry->set('field_end_time', $end);

    // The intervention is now the defining attribution — the end time is
    // retroactive — so it overrides the original wo_clock_button value.
    $entry->set('field_source', 'wo_clock_intervention');
    if ($auditNote) {
      // Stamp reflects the resolved end time, not the moment of close.
      $stamp = $this->usDisplay($endTimestamp ?? $this->time->getRequestTime());
      $this->appendNote($entry, sprintf(self::NOTE_INTERVENTION_END, $stamp, $this->currentUser->getDisplayName()));
    }
    $entry->_wo_clock_write = TRUE;

    // Bypass the Phase 1 wo_time_clock guards ONLY when the parent WO is
    // locked (Invoiced/Paid) — a legitimate office correction on billed work.
    if ($this->parentWoIsLocked($entry)) {
      $entry->_signoff_reconciliation = TRUE;
    }

    $entry->save();
    return $entry;
  }

  /**
   * Haversine distance in feet.
   */
  public function calculateDistanceFeet(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
      + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return self::EARTH_RADIUS_FEET * $c;
  }

  /**
   * The WO's property location from field_property → field_geofield.
   *
   * @return array{lat: float, lon: float}|null
   */
  public function getPropertyLocation(int $woId): ?array {
    if ($woId <= 0) {
      return NULL;
    }
    $wo = $this->entityTypeManager->getStorage('work_order')->load($woId);
    if (!$wo || !$wo->hasField('field_property') || $wo->get('field_property')->isEmpty()) {
      return NULL;
    }
    $property = $wo->get('field_property')->entity;
    if (!$property || !$property->hasField('field_geofield') || $property->get('field_geofield')->isEmpty()) {
      return NULL;
    }
    $geo = $property->get('field_geofield')->first()->getValue();
    if (!isset($geo['lat'], $geo['lon'])) {
      return NULL;
    }
    return ['lat' => (float) $geo['lat'], 'lon' => (float) $geo['lon']];
  }

  // -- internal helpers ------------------------------------------------------

  private function loadEntry(int $entryId): EntityInterface {
    $entry = $this->tcStorage()->load($entryId);
    if (!$entry) {
      throw new \InvalidArgumentException("wo_time_clock entry $entryId not found");
    }
    return $entry;
  }

  private function wkt(float $lat, float $lon): string {
    // Geofield WKT is POINT (lon lat).
    return sprintf('POINT (%F %F)', $lon, $lat);
  }

  /**
   * Append a structured note line to field_notes, newline-separated. Notes
   * accumulate across a start and a later end — existing content is never
   * replaced.
   */
  private function appendNote(EntityInterface $entry, string $line): void {
    $existing = (string) ($entry->get('field_notes')->value ?? '');
    $entry->set('field_notes', $existing === '' ? $line : $existing . "\n" . $line);
  }

  private function usDisplay(int $timestamp): string {
    $dt = new \DateTime('@' . $timestamp);
    $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    return $dt->format('m/d/Y g:i A');
  }

  private function parentWoIsLocked(EntityInterface $entry): bool {
    if (!$entry->hasField('field_work_order') || $entry->get('field_work_order')->isEmpty()) {
      return FALSE;
    }
    $wo = $entry->get('field_work_order')->entity;
    if (!$wo || !$wo->hasField('field_status')) {
      return FALSE;
    }
    return in_array((int) $wo->get('field_status')->target_id, self::LOCKED_STATUSES, TRUE);
  }

}
