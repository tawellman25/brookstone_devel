<?php

declare(strict_types=1);

namespace Drupal\bos_teammate_operations\Service;

use Drupal\config_pages\ConfigPagesLoaderServiceInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Inspects wo_time_clock entries for the five canonical anomaly types
 * surfaced by the data hygiene check (Phase 2B/2B.1) and reused by the
 * per-teammate detail page (Phase 2C).
 *
 * Five categories are checked:
 *
 *   - negative_hours       field_total_time < 0
 *   - implausible_long     field_total_time > 16
 *   - future_start         field_start_time > today (local TZ)
 *   - open_stale           no field_end_time AND field_start_time > 7 days ago
 *   - time_travel          field_end_time < field_start_time
 *
 * The data-check page used to do this inline; pulling it out lets the
 * teammate detail page mark individual rows without duplicating logic.
 */
final class AnomalyDetectionService {

  /**
   * Default long-shift threshold in hours. Used as fallback only when
   * business_setting.field_long_shift_hours is unavailable. Live value
   * should always come from that field — see getLongShiftHours().
   */
  private const HOURS_LONG = 16.0;

  /**
   * Default open-stale threshold in days. Used as fallback only when
   * business_setting.field_stale_clock_out_days is unavailable. Live
   * value should always come from that field — see getStaleClockOutDays().
   */
  private const DAYS_STALE = 7;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CompensableHoursService $compensableHours,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ConfigPagesLoaderServiceInterface $configPagesLoader,
  ) {}

  /**
   * Reads the long-shift threshold from business_setting.
   *
   * Falls back to self::HOURS_LONG (16.0) if the field is missing or empty.
   */
  private function getLongShiftHours(): float {
    $cfg = $this->configPagesLoader->load('business_setting');
    if (!$cfg || !$cfg->hasField('field_long_shift_hours') || $cfg->get('field_long_shift_hours')->isEmpty()) {
      return self::HOURS_LONG;
    }
    $value = $cfg->get('field_long_shift_hours')->value;
    return is_numeric($value) ? (float) $value : self::HOURS_LONG;
  }

  /**
   * Reads the stale-clock-out threshold from business_setting.
   *
   * Falls back to self::DAYS_STALE (7) if the field is missing or empty.
   */
  private function getStaleClockOutDays(): int {
    $cfg = $this->configPagesLoader->load('business_setting');
    if (!$cfg || !$cfg->hasField('field_stale_clock_out_days') || $cfg->get('field_stale_clock_out_days')->isEmpty()) {
      return self::DAYS_STALE;
    }
    $value = $cfg->get('field_stale_clock_out_days')->value;
    return is_numeric($value) ? (int) $value : self::DAYS_STALE;
  }

  // ── Public API ─────────────────────────────────────────────────────────

  /**
   * Check a single wo_time_clock entry for anomalies.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entry
   *   A loaded wo_time_clock:entry entity.
   *
   * @return array<int, array{type: string, message: string, severity: string}>
   *   Zero or more anomaly descriptors. Empty array when clean.
   */
  public function detectAnomalies(EntityInterface $entry): array {
    if ($entry->getEntityTypeId() !== 'wo_time_clock') {
      return [];
    }
    $found = [];

    $total = $entry->hasField('field_total_time') && !$entry->get('field_total_time')->isEmpty()
      ? (float) $entry->get('field_total_time')->value
      : NULL;
    $start = $entry->hasField('field_start_time') && !$entry->get('field_start_time')->isEmpty()
      ? (string) $entry->get('field_start_time')->value
      : '';
    $end = $entry->hasField('field_end_time') && !$entry->get('field_end_time')->isEmpty()
      ? (string) $entry->get('field_end_time')->value
      : '';

    if ($total !== NULL && $total < 0) {
      $found[] = [
        'type' => 'negative_hours',
        'message' => 'Negative hours: ' . number_format($total, 2),
        'severity' => 'high',
      ];
    }
    if ($total !== NULL && $total > $this->getLongShiftHours()) {
      $found[] = [
        'type' => 'implausible_long',
        'message' => 'Implausibly long shift: ' . number_format($total, 2) . ' hrs',
        'severity' => 'high',
      ];
    }

    if ($start !== '' && strtotime($start) > strtotime('tomorrow 00:00:00')) {
      $found[] = [
        'type' => 'future_start',
        'message' => 'Start time is in the future: ' . $start,
        'severity' => 'high',
      ];
    }

    if ($start !== '' && $end === '') {
      $age_seconds = time() - (int) strtotime($start);
      $age_days = (int) floor($age_seconds / 86400);
      if ($age_days > $this->getStaleClockOutDays()) {
        $found[] = [
          'type' => 'open_stale',
          'message' => 'Forgotten clock-out: ' . $age_days . ' days open',
          'severity' => 'high',
        ];
      }
    }

    if ($start !== '' && $end !== '' && strtotime($end) < strtotime($start)) {
      $found[] = [
        'type' => 'time_travel',
        'message' => 'End time is before start time',
        'severity' => 'high',
      ];
    }

    return $found;
  }

  /**
   * @return array<string, string>  Machine name => human label.
   *   Labels include the live threshold values from business_setting so
   *   the dashboard reflects configured values rather than the original
   *   defaults of 16 hrs / 7 days.
   */
  public function getAnomalyTypes(): array {
    $hours = number_format($this->getLongShiftHours(), 1);
    // Trim trailing .0 for cleaner display ("16" not "16.0").
    $hours = rtrim(rtrim($hours, '0'), '.');
    $days = $this->getStaleClockOutDays();
    return [
      'negative_hours'   => 'Negative total_time',
      'implausible_long' => "Implausibly long shift (> {$hours} hrs)",
      'future_start'     => 'Future start_time',
      'open_stale'       => "Forgotten clock-out (> {$days} days open)",
      'time_travel'      => 'End time before start time',
    ];
  }

  /**
   * Find all wo_time_clock entries matching a single anomaly type
   * across all users. Used by the data-check page; per-user dashboards
   * should use getAnomalousEntriesForUser() instead.
   *
   * @param string $type
   *   One of the keys returned by getAnomalyTypes().
   *
   * @return EntityInterface[]
   *   Loaded entries (capped at 5000 to bound memory; in practice the
   *   live dataset has hundreds, not thousands).
   */
  public function findAnomaliesByType(string $type): array {
    $storage = $this->entityTypeManager->getStorage('wo_time_clock');
    $ids = [];
    try {
      switch ($type) {
        case 'negative_hours':
          $ids = $storage->getQuery()->accessCheck(FALSE)
            ->condition('field_total_time', 0, '<')
            ->range(0, 5000)
            ->execute();
          break;

        case 'implausible_long':
          $ids = $storage->getQuery()->accessCheck(FALSE)
            ->condition('field_total_time', $this->getLongShiftHours(), '>')
            ->range(0, 5000)
            ->execute();
          break;

        case 'future_start':
          $todayUtcEnd = (new \DateTime('tomorrow 00:00:00', new \DateTimeZone(date_default_timezone_get())))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s');
          $ids = $storage->getQuery()->accessCheck(FALSE)
            ->condition('field_start_time', $todayUtcEnd, '>')
            ->range(0, 5000)
            ->execute();
          break;

        case 'open_stale':
          $cutoff = (new \DateTime('-' . $this->getStaleClockOutDays() . ' days', new \DateTimeZone(date_default_timezone_get())))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s');
          $ids = $storage->getQuery()->accessCheck(FALSE)
            ->notExists('field_end_time')
            ->condition('field_start_time', $cutoff, '<')
            ->range(0, 5000)
            ->execute();
          break;

        case 'time_travel':
          // Entity query can't compare two fields directly — load
          // a window and filter in PHP.
          $candidates = $storage->getQuery()->accessCheck(FALSE)
            ->exists('field_start_time')
            ->exists('field_end_time')
            ->sort('field_start_time', 'DESC')
            ->range(0, 5000)
            ->execute();
          if (empty($candidates)) {
            return [];
          }
          $loaded = $storage->loadMultiple($candidates);
          $bad = [];
          foreach ($loaded as $entry) {
            $s = $entry->get('field_start_time')->value;
            $e = $entry->get('field_end_time')->value;
            if ($s && $e && strtotime($e) < strtotime($s)) {
              $bad[] = $entry;
            }
          }
          return $bad;
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('bos_teammate_operations')->error(
        'findAnomaliesByType(@t) failed: @msg',
        ['@t' => $type, '@msg' => $e->getMessage()]
      );
      return [];
    }
    if (empty($ids)) {
      return [];
    }
    return array_values($storage->loadMultiple($ids));
  }

  /**
   * Count anomalous entries for a user in a date range.
   *
   * @param int $uid
   * @param string $startDate Local-tz 'Y-m-d'.
   * @param string $endDate   Local-tz 'Y-m-d'.
   * @param bool $boundaryAware
   *   When TRUE (default), excludes entries whose start_time is before
   *   the data quality boundary.
   *
   * @return int
   */
  public function countAnomaliesForUser(
    int $uid,
    string $startDate,
    string $endDate,
    bool $boundaryAware = TRUE,
  ): int {
    return count($this->getAnomalousEntriesForUser($uid, $startDate, $endDate, $boundaryAware));
  }

  /**
   * @return EntityInterface[]
   *   wo_time_clock entries flagged as anomalous in the date range.
   */
  public function getAnomalousEntriesForUser(
    int $uid,
    string $startDate,
    string $endDate,
    bool $boundaryAware = TRUE,
  ): array {
    [$startUtc, $endUtc] = $this->dateRangeUtc($startDate, $endDate);
    try {
      // Load any entries for this user whose start_time falls in the
      // window OR that have a NULL end_time (forgotten clock-outs may
      // pre-date the window but still need to be surfaced).
      $base = $this->entityTypeManager->getStorage('wo_time_clock')->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_teammate', $uid);

      $orGroup = $base->orConditionGroup()
        ->condition($base->andConditionGroup()
          ->condition('field_start_time', $startUtc, '>=')
          ->condition('field_start_time', $endUtc, '<=')
        )
        ->condition($base->andConditionGroup()
          ->notExists('field_end_time')
        );
      $base->condition($orGroup);

      $ids = $base->execute();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('bos_teammate_operations')->error(
        'getAnomalousEntriesForUser query failed for uid @u: @msg',
        ['@u' => $uid, '@msg' => $e->getMessage()]
      );
      return [];
    }
    if (empty($ids)) {
      return [];
    }

    $entries = $this->entityTypeManager->getStorage('wo_time_clock')->loadMultiple($ids);
    $boundaryStr = $boundaryAware
      ? $this->compensableHours->getDataQualityBoundary()->format('Y-m-d')
      : NULL;

    $result = [];
    foreach ($entries as $entry) {
      $anoms = $this->detectAnomalies($entry);
      if (empty($anoms)) {
        continue;
      }
      if ($boundaryAware && $boundaryStr !== NULL) {
        $start = $entry->hasField('field_start_time') && !$entry->get('field_start_time')->isEmpty()
          ? substr((string) $entry->get('field_start_time')->value, 0, 10)
          : '';
        if ($start !== '' && $start < $boundaryStr) {
          continue;
        }
      }
      $result[] = $entry;
    }
    return $result;
  }

  // ── Internal helpers ───────────────────────────────────────────────────

  private function dateRangeUtc(string $startDate, string $endDate): array {
    $localTz = new \DateTimeZone(date_default_timezone_get());
    $utc = new \DateTimeZone('UTC');
    $start = new \DateTime($startDate . ' 00:00:00', $localTz);
    $end = new \DateTime($endDate . ' 23:59:59', $localTz);
    $start->setTimezone($utc);
    $end->setTimezone($utc);
    return [
      $start->format('Y-m-d\TH:i:s'),
      $end->format('Y-m-d\TH:i:s'),
    ];
  }

}
