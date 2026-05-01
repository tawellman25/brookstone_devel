<?php

declare(strict_types=1);

namespace Drupal\bos_teammate_operations\Service;

use Drupal\config_pages\ConfigPagesLoaderServiceInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Computes compensable, WO, and variance hours for a teammate on a date.
 *
 * The whole point of this service is to be the single swap point for the
 * eventual TimeTrax integration. Phase 2 (current) returns an 8.5-hour
 * assumption whenever a teammate has any WO activity on a given date.
 * Phase 3 (TimeTrax integration) will replace ONE method body —
 * getCompensableHoursForUserOnDate() — to sum real time_clock_entry rows
 * for that user/date. Every dashboard that depends on this service
 * keeps working with no changes.
 *
 * See:
 *   - __BOS_AI/Strategy/timetrax_strategy.md (strategic context)
 *   - __BOS_AI/Modules/bos_teammate_operations.md (this module's plan)
 */
final class CompensableHoursService {

  /** Default assumed daily hours when business_setting field is empty. */
  private const DEFAULT_ASSUMED_DAILY_HOURS = 8.5;

  /** Default green threshold (variance < this is fine). */
  private const DEFAULT_GREEN_MAX = 1.5;

  /** Default yellow threshold (variance <= this is worth a look). */
  private const DEFAULT_YELLOW_MAX = 3.0;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigPagesLoaderServiceInterface $configPagesLoader,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Compensable hours for a teammate on a given local date.
   *
   * Phase 2: returns getAssumedDailyHours() if there is any closed WO time
   * clock activity for this user on this date, else 0.0.
   *
   * Phase 3 (TimeTrax integration) will replace this method body with a
   * sum of time_clock_entry rows for the user/date. The signature must
   * stay identical.
   *
   * @param int $uid
   *   The user id.
   * @param string $date
   *   A local-timezone date string in 'Y-m-d' format.
   *
   * @return float
   *   Compensable hours for the date.
   */
  public function getCompensableHoursForUserOnDate(int $uid, string $date): float {
    return $this->hasWoActivityOnDate($uid, $date)
      ? $this->getAssumedDailyHours()
      : 0.0;
  }

  /**
   * Reads field_assumed_daily_hours from business_setting.
   *
   * @return float
   *   Configured assumed daily hours, or 8.5 if unset.
   */
  public function getAssumedDailyHours(): float {
    $cfg = $this->configPagesLoader->load('business_setting');
    if (!$cfg || !$cfg->hasField('field_assumed_daily_hours') || $cfg->get('field_assumed_daily_hours')->isEmpty()) {
      return self::DEFAULT_ASSUMED_DAILY_HOURS;
    }
    $value = $cfg->get('field_assumed_daily_hours')->value;
    return is_numeric($value) ? (float) $value : self::DEFAULT_ASSUMED_DAILY_HOURS;
  }

  /**
   * Reads variance thresholds from business_setting.
   *
   * @return array
   *   ['green_max' => float, 'yellow_max' => float]. Defaults when unset.
   */
  public function getVarianceThresholds(): array {
    $green = self::DEFAULT_GREEN_MAX;
    $yellow = self::DEFAULT_YELLOW_MAX;
    $cfg = $this->configPagesLoader->load('business_setting');
    if ($cfg) {
      if ($cfg->hasField('field_variance_green_max') && !$cfg->get('field_variance_green_max')->isEmpty()) {
        $raw = $cfg->get('field_variance_green_max')->value;
        if (is_numeric($raw)) {
          $green = (float) $raw;
        }
      }
      if ($cfg->hasField('field_variance_yellow_max') && !$cfg->get('field_variance_yellow_max')->isEmpty()) {
        $raw = $cfg->get('field_variance_yellow_max')->value;
        if (is_numeric($raw)) {
          $yellow = (float) $raw;
        }
      }
    }
    return ['green_max' => $green, 'yellow_max' => $yellow];
  }

  /**
   * Sums field_total_time across closed wo_time_clock entries for a
   * user on a given local date.
   *
   * "Closed" means field_end_time IS NOT NULL — open punches are
   * excluded so a teammate currently clocked in is not double-counted.
   *
   * @param int $uid
   * @param string $date
   *   Local-timezone date string ('Y-m-d').
   *
   * @return float
   *   Sum of total_time hours, rounded to 2 decimals.
   */
  public function getWoHoursForUserOnDate(int $uid, string $date): float {
    [$startUtc, $endUtc] = $this->dateRangeUtc($date);
    try {
      $ids = $this->entityTypeManager->getStorage('wo_time_clock')->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_teammate', $uid)
        ->condition('field_start_time', $startUtc, '>=')
        ->condition('field_start_time', $endUtc, '<=')
        ->exists('field_end_time')
        ->execute();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('bos_teammate_operations')->error(
        'getWoHoursForUserOnDate query failed for uid @u date @d: @msg',
        ['@u' => $uid, '@d' => $date, '@msg' => $e->getMessage()]
      );
      return 0.0;
    }
    if (empty($ids)) {
      return 0.0;
    }
    $entries = $this->entityTypeManager->getStorage('wo_time_clock')->loadMultiple($ids);
    $total = 0.0;
    foreach ($entries as $entry) {
      if ($entry->hasField('field_total_time') && !$entry->get('field_total_time')->isEmpty()) {
        $raw = $entry->get('field_total_time')->value;
        if (is_numeric($raw)) {
          $total += (float) $raw;
        }
      }
    }
    return round($total, 2);
  }

  /**
   * Variance = compensable - WO. Positive = unaccounted time;
   * negative = overtime / WO entries beyond a normal shift.
   *
   * @param int $uid
   * @param string $date
   *
   * @return float
   */
  public function getVarianceForUserOnDate(int $uid, string $date): float {
    return round(
      $this->getCompensableHoursForUserOnDate($uid, $date)
        - $this->getWoHoursForUserOnDate($uid, $date),
      2
    );
  }

  /**
   * Classifies a variance number against configured thresholds.
   *
   * @param float $variance
   *   Compensable - WO hours.
   * @param bool $hadActivity
   *   TRUE when the teammate had any closed WO activity on the date;
   *   FALSE for "no activity" days (returns 'na').
   *
   * @return string
   *   One of: 'na' | 'green' | 'yellow' | 'red'.
   */
  public function getVarianceStatus(float $variance, bool $hadActivity): string {
    if (!$hadActivity) {
      return 'na';
    }
    $t = $this->getVarianceThresholds();
    $abs = abs($variance);
    if ($abs < $t['green_max']) {
      return 'green';
    }
    if ($abs <= $t['yellow_max']) {
      return 'yellow';
    }
    return 'red';
  }

  /**
   * Productive percentage = WO hours / compensable hours * 100.
   *
   * @param int $uid
   * @param string $date
   *
   * @return float|null
   *   Percentage 0-100+, or NULL if no compensable hours on the date.
   */
  public function getProductivePercentageForUserOnDate(int $uid, string $date): ?float {
    $compensable = $this->getCompensableHoursForUserOnDate($uid, $date);
    if ($compensable <= 0.0) {
      return NULL;
    }
    $wo = $this->getWoHoursForUserOnDate($uid, $date);
    return round(($wo / $compensable) * 100.0, 1);
  }

  /**
   * TRUE when the teammate has at least one closed wo_time_clock entry
   * starting within the given local date.
   *
   * Public so callers (dashboards, UI) can ask "did this person work
   * today?" without re-deriving from compensable_hours > 0 — that
   * coupling will break in Phase 3 once compensable hours come from
   * an external clock instead of being inferred from WO activity.
   *
   * @param int $uid
   * @param string $date
   *
   * @return bool
   */
  public function hasWoActivityOnDate(int $uid, string $date): bool {
    [$startUtc, $endUtc] = $this->dateRangeUtc($date);
    try {
      $count = $this->entityTypeManager->getStorage('wo_time_clock')->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_teammate', $uid)
        ->condition('field_start_time', $startUtc, '>=')
        ->condition('field_start_time', $endUtc, '<=')
        ->exists('field_end_time')
        ->count()
        ->execute();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('bos_teammate_operations')->error(
        'hasWoActivityOnDate query failed for uid @u date @d: @msg',
        ['@u' => $uid, '@d' => $date, '@msg' => $e->getMessage()]
      );
      return FALSE;
    }
    return ((int) $count) > 0;
  }

  /**
   * Convert a local-tz 'Y-m-d' date into a [start_utc, end_utc] pair
   * formatted for Drupal datetime field comparison.
   *
   * Uses date_default_timezone_get() rather than a hardcoded TZ; BOS
   * has had MariaDB session-tz issues in the past and the convention
   * is to honor the runtime default.
   *
   * @param string $date
   *
   * @return array{0: string, 1: string}
   *   [start_utc, end_utc] as 'Y-m-d\TH:i:s' strings.
   */
  private function dateRangeUtc(string $date): array {
    $localTz = new \DateTimeZone(date_default_timezone_get());
    $utc = new \DateTimeZone('UTC');
    $start = new \DateTime($date . ' 00:00:00', $localTz);
    $end = new \DateTime($date . ' 23:59:59', $localTz);
    $start->setTimezone($utc);
    $end->setTimezone($utc);
    return [
      $start->format('Y-m-d\TH:i:s'),
      $end->format('Y-m-d\TH:i:s'),
    ];
  }

}
