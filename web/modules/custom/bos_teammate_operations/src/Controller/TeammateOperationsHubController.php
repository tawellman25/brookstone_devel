<?php

declare(strict_types=1);

namespace Drupal\bos_teammate_operations\Controller;

use Drupal\bos_teammate_operations\Service\AnomalyDetectionService;
use Drupal\bos_teammate_operations\Service\CompensableHoursService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Teammate Operations Hub landing page (Phase 2D).
 *
 * Path: /admin/office/operations/teammates
 *
 * Single front-door for the variance suite. Six top-line stat cards,
 * navigation grid (active + planned), recent anomalies snippet, and a
 * boundary-date footer for transparency.
 *
 * Read-only — never mutates wo_time_clock. Same role gate as the rest
 * of the suite.
 */
final class TeammateOperationsHubController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Team-average productivity thresholds (HUB-LEVEL, distinct from
   * the per-day variance thresholds in business_setting). The team
   * average is a different statistic — the spec calls for fixed
   * thresholds here.
   */
  private const TEAM_AVG_RED    = 50.0;
  private const TEAM_AVG_YELLOW = 70.0;

  public function __construct(
    private readonly CompensableHoursService $compensableHours,
    private readonly AnomalyDetectionService $anomalyDetection,
    private readonly EntityTypeManagerInterface $em,
    private readonly DateFormatterInterface $dateFmt,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('bos_teammate_operations.compensable_hours'),
      $container->get('bos_teammate_operations.anomaly_detection'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  // ──────────────────────────────────────────────────────────────────────
  // MAIN BUILD
  // ──────────────────────────────────────────────────────────────────────

  public function build(): array {
    $build = [];
    $build['#attached']['library'][] = 'bos_teammate_operations/variance_dashboard';

    $build['header'] = [
      '#markup' => '<div class="bos-hub-header">'
        . '<h1>Teammate Operations</h1>'
        . '<p class="bos-hub-subtitle">Visibility into teammate productivity, activity, and operational health.</p>'
        . '<p class="bos-hub-timestamp">As of ' . $this->dateFmt->format(time(), 'custom', 'D M j, Y g:i A') . '</p>'
        . '</div>',
      '#allowed_tags' => ['div', 'h1', 'p'],
    ];

    $build['stats']      = $this->buildStatsRow();
    $build['nav_grid']   = $this->buildNavigationGrid();
    $build['anomalies']  = $this->buildRecentAnomalies();

    $boundary = $this->compensableHours->getDataQualityBoundary()->format('Y-m-d');
    $build['footer'] = [
      '#markup' => '<div class="bos-hub-footer">'
        . 'Data quality boundary: <strong>' . htmlspecialchars($this->formatDateUs($boundary)) . '</strong>. '
        . 'Records before this date are considered legacy and excluded from default views. '
        . 'Adjust at <a href="/admin/config/system/config_pages/business_setting">'
        . '/admin/config/system/config_pages/business_setting</a> if needed.'
        . '</div>',
      '#allowed_tags' => ['div', 'strong', 'a'],
    ];

    return $build;
  }

  // ──────────────────────────────────────────────────────────────────────
  // STATS ROW
  // ──────────────────────────────────────────────────────────────────────

  protected function buildStatsRow(): array {
    $today = date('Y-m-d');
    $boundary = $this->compensableHours->getDataQualityBoundary()->format('Y-m-d');

    // Stat 1 — Active teammates today
    $activeToday = $this->getActiveTeammatesToday();

    // Stat 2 — Active WOs (currently open punches)
    $activeWosNow = $this->getActiveWosNow();

    // Stat 3 — Active today but no open WO
    $betweenWos = $this->getTeammatesActiveButNoOpenWo();

    // Stat 4 — Active anomalies since boundary
    $anomCount = $this->getTotalActiveAnomalyCount();

    // Stat 5 — Team avg productive % (last 7 days)
    $teamAvg7 = $this->getTeamAvgProductivePercent(7);

    // Stat 6 — Lowest productive % (last 30 days)
    $lowest = $this->getLowestProductivityTeammate(30);

    // Card render helpers
    $cards = [];

    $cards[] = $this->card(
      'Active Teammates Today',
      (string) $activeToday,
      'punched into a WO today',
      ''
    );

    $cards[] = $this->card(
      'Active WOs Now',
      (string) $activeWosNow,
      'currently in progress',
      ''
    );

    $cards[] = $this->card(
      'Active But No Open WO',
      (string) $betweenWos,
      'between work orders',
      $betweenWos > 0 ? 'bos-stat-warn' : ''
    );

    $cards[] = $this->card(
      'Active Anomalies (since ' . $this->formatDateUs($boundary) . ')',
      (string) $anomCount,
      'data hygiene items',
      $anomCount > 0 ? 'bos-stat-warn' : 'bos-variance-green',
      Url::fromRoute('bos_teammate_operations.variance_data_check')->toString()
    );

    $cards[] = $this->card(
      'Avg Productive % (last 7 days)',
      $teamAvg7 === NULL ? '—' : number_format($teamAvg7, 1) . '%',
      'team average, last 7 days',
      $this->teamAvgClass($teamAvg7)
    );

    $lowestValue = $lowest['uid']
      ? htmlspecialchars($lowest['name']) . ': ' . number_format($lowest['pct'], 1) . '%'
      : '—';
    $cards[] = $this->card(
      'Lowest Productive % (30 days)',
      $lowestValue,
      'click to see ranked list',
      'bos-variance-red',
      Url::fromRoute('bos_teammate_operations.variance_daily', [], [
        'query' => ['order' => 'Productive %', 'sort' => 'asc'],
      ])->toString()
    );

    $html = '<div class="bos-stat-grid bos-hub-stats">';
    foreach ($cards as $card) {
      $html .= $card;
    }
    $html .= '</div>';

    return [
      '#markup' => $html,
      '#allowed_tags' => ['div', 'a'],
    ];
  }

  protected function card(string $label, string $value, string $sub, string $class, ?string $linkUrl = NULL): string {
    $inner = '<div class="bos-stat-label">' . htmlspecialchars($label) . '</div>'
      . '<div class="bos-stat-value">' . $value . '</div>'
      . ($sub ? '<div class="bos-stat-sub">' . htmlspecialchars($sub) . '</div>' : '');
    if ($linkUrl) {
      return '<a class="bos-stat-card ' . $class . ' bos-stat-link" href="' . htmlspecialchars($linkUrl) . '">' . $inner . '</a>';
    }
    return '<div class="bos-stat-card ' . $class . '">' . $inner . '</div>';
  }

  protected function teamAvgClass(?float $pct): string {
    if ($pct === NULL) return '';
    if ($pct < self::TEAM_AVG_RED)    return 'bos-variance-red';
    if ($pct < self::TEAM_AVG_YELLOW) return 'bos-variance-yellow';
    return 'bos-variance-green';
  }

  // ──────────────────────────────────────────────────────────────────────
  // NAV GRID
  // ──────────────────────────────────────────────────────────────────────

  protected function buildNavigationGrid(): array {
    $cards = [
      [
        'icon' => '📊', 'title' => 'Daily Variance',
        'desc' => 'See compensable hours vs WO hours per teammate over a date range. Find patterns of underperformance or data hygiene issues.',
        'url'  => Url::fromRoute('bos_teammate_operations.variance_daily')->toString(),
        'badge' => 'ACTIVE', 'badge_class' => 'badge-active',
      ],
      [
        'icon' => '🔍', 'title' => 'Data Hygiene Check',
        'desc' => 'Identify wo_time_clock records with anomalies (negative hours, forgotten clock-outs, impossibly long shifts) that may distort variance numbers.',
        'url'  => Url::fromRoute('bos_teammate_operations.variance_data_check')->toString(),
        'badge' => 'ACTIVE', 'badge_class' => 'badge-active',
      ],
      [
        'icon' => '⏰', 'title' => 'Active Now',
        'desc' => "See who's currently clocked into a work order, where they're working, and how long they've been on each WO.",
        'url'  => NULL,
        'badge' => 'PLANNED — Phase 2E', 'badge_class' => 'badge-planned',
      ],
      [
        'icon' => '📈', 'title' => 'Weekly Trends',
        'desc' => 'Per-teammate productivity patterns over rolling 4-week and 8-week windows. Spot trends rather than reacting to single bad days.',
        'url'  => NULL,
        'badge' => 'PLANNED — Phase 2F', 'badge_class' => 'badge-planned',
      ],
      [
        'icon' => '👥', 'title' => 'Team Roster',
        'desc' => 'All active teammates with key facts: department, role, hire date, certifications, equipment assignments.',
        'url'  => NULL,
        'badge' => 'PLANNED — Tier 2', 'badge_class' => 'badge-planned',
      ],
      [
        'icon' => '📅', 'title' => "Today's Schedule",
        'desc' => "Who's scheduled today, who's started, who hasn't shown up yet.",
        'url'  => NULL,
        'badge' => 'PLANNED — Tier 2', 'badge_class' => 'badge-planned',
      ],
    ];

    $html = '<h2 class="bos-hub-section-heading">Variance Suite</h2>';
    $html .= '<div class="bos-hub-nav-grid">';
    foreach ($cards as $c) {
      $isPlanned = empty($c['url']);
      $cls = 'bos-nav-card' . ($isPlanned ? ' bos-nav-card-planned' : '');
      $html .= '<div class="' . $cls . '">';
      $html .= '<div class="bos-nav-card-icon">' . $c['icon'] . '</div>';
      $html .= '<div class="bos-nav-card-title">' . htmlspecialchars($c['title']) . '</div>';
      $html .= '<div class="bos-nav-card-desc">' . htmlspecialchars($c['desc']) . '</div>';
      $html .= '<div class="bos-nav-card-footer">';
      $html .= '<span class="bos-nav-card-badge ' . $c['badge_class'] . '">' . htmlspecialchars($c['badge']) . '</span>';
      if (!$isPlanned) {
        $html .= '<a class="bos-nav-card-link" href="' . htmlspecialchars($c['url']) . '">View →</a>';
      }
      $html .= '</div>';
      $html .= '</div>';
    }
    $html .= '</div>';

    return [
      '#markup' => $html,
      '#allowed_tags' => ['h2', 'div', 'span', 'a'],
    ];
  }

  // ──────────────────────────────────────────────────────────────────────
  // RECENT ANOMALIES
  // ──────────────────────────────────────────────────────────────────────

  protected function buildRecentAnomalies(): array {
    // Pull 5 most recent active anomalies across all types.
    $boundary = $this->compensableHours->getDataQualityBoundary()->format('Y-m-d');
    $allActive = [];
    foreach (array_keys($this->anomalyDetection->getAnomalyTypes()) as $type) {
      foreach ($this->anomalyDetection->findAnomaliesByType($type) as $entry) {
        $start = $entry->hasField('field_start_time') && !$entry->get('field_start_time')->isEmpty()
          ? substr((string) $entry->get('field_start_time')->value, 0, 10)
          : NULL;
        if ($start !== NULL && $start < $boundary) {
          continue;
        }
        $key = ((int) $entry->id()) . '-' . $type;
        $allActive[$key] = ['entry' => $entry, 'type' => $type, 'start' => $start ?? '—'];
      }
    }
    $totalActive = count($allActive);

    // Sort by start_time DESC and take 5
    usort($allActive, fn($a, $b) => strcmp($b['start'] ?? '', $a['start'] ?? ''));
    $top5 = array_slice($allActive, 0, 5);

    $html = '<h2 class="bos-hub-section-heading">Recent Active Anomalies</h2>';

    if ($totalActive === 0) {
      $html .= '<div class="bos-hub-anomalies-clean">✓ No active anomalies. Data hygiene is clean.</div>';
      return ['#markup' => $html, '#allowed_tags' => ['h2', 'div']];
    }

    $html .= '<table class="bos-hub-anomalies-list">';
    $html .= '<thead><tr><th>Date</th><th>Teammate</th><th>Type</th><th>Detail</th></tr></thead><tbody>';
    foreach ($top5 as $item) {
      $entry = $item['entry'];
      $date = $item['start'];
      $type = $item['type'];
      $teammateLabel = '—';
      $teammateLink = '—';
      if ($entry->hasField('field_teammate') && !$entry->get('field_teammate')->isEmpty()) {
        $u = $entry->get('field_teammate')->entity;
        if ($u) {
          $teammateLabel = $u->getDisplayName();
          $detailUrl = Url::fromRoute(
            'bos_teammate_operations.variance_teammate_detail',
            ['user' => $u->id()],
          )->toString();
          $teammateLink = '<a href="' . htmlspecialchars($detailUrl) . '">' . htmlspecialchars($teammateLabel) . '</a>';
        }
      }
      $detected = $this->anomalyDetection->detectAnomalies($entry);
      $detail = '';
      foreach ($detected as $d) {
        if ($d['type'] === $type) {
          $detail = $d['message'];
          break;
        }
      }
      $typeLabel = $this->anomalyDetection->getAnomalyTypes()[$type] ?? $type;
      $html .= '<tr>'
        . '<td>' . htmlspecialchars($this->formatDateUs($date)) . '</td>'
        . '<td>' . $teammateLink . '</td>'
        . '<td>' . htmlspecialchars($typeLabel) . '</td>'
        . '<td>' . htmlspecialchars($detail) . '</td>'
        . '</tr>';
    }
    $html .= '</tbody></table>';

    $dataCheckUrl = Url::fromRoute('bos_teammate_operations.variance_data_check')->toString();
    $html .= '<p class="bos-hub-anomalies-link">'
      . '<a href="' . htmlspecialchars($dataCheckUrl) . '">View all ' . $totalActive . ' active anomalies →</a>'
      . '</p>';

    return ['#markup' => $html, '#allowed_tags' => ['h2', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'a', 'p', 'div']];
  }

  // ──────────────────────────────────────────────────────────────────────
  // STAT QUERIES
  // ──────────────────────────────────────────────────────────────────────

  /** Distinct teammates with at least one wo_time_clock entry today. */
  protected function getActiveTeammatesToday(): int {
    [$startUtc, $endUtc] = $this->todayRangeUtc();
    try {
      $ids = $this->em->getStorage('wo_time_clock')->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_start_time', $startUtc, '>=')
        ->condition('field_start_time', $endUtc, '<=')
        ->exists('field_teammate')
        ->execute();
    }
    catch (\Throwable $e) {
      return 0;
    }
    if (empty($ids)) return 0;
    $entries = $this->em->getStorage('wo_time_clock')->loadMultiple($ids);
    $uids = [];
    foreach ($entries as $entry) {
      $uid = (int) $entry->get('field_teammate')->target_id;
      if ($uid > 0) $uids[$uid] = TRUE;
    }
    return count($uids);
  }

  /** Distinct work_order entities with currently-open punches. */
  protected function getActiveWosNow(): int {
    try {
      $ids = $this->em->getStorage('wo_time_clock')->getQuery()
        ->accessCheck(FALSE)
        ->exists('field_start_time')
        ->notExists('field_end_time')
        ->exists('field_work_order')
        ->execute();
    }
    catch (\Throwable $e) {
      return 0;
    }
    if (empty($ids)) return 0;
    $entries = $this->em->getStorage('wo_time_clock')->loadMultiple($ids);
    $woIds = [];
    foreach ($entries as $entry) {
      $woId = (int) $entry->get('field_work_order')->target_id;
      if ($woId > 0) $woIds[$woId] = TRUE;
    }
    return count($woIds);
  }

  /** Teammates active today AND who currently have NO open punch. */
  protected function getTeammatesActiveButNoOpenWo(): int {
    [$startUtc, $endUtc] = $this->todayRangeUtc();
    try {
      $activeTodayIds = $this->em->getStorage('wo_time_clock')->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_start_time', $startUtc, '>=')
        ->condition('field_start_time', $endUtc, '<=')
        ->exists('field_teammate')
        ->execute();
      $openIds = $this->em->getStorage('wo_time_clock')->getQuery()
        ->accessCheck(FALSE)
        ->exists('field_start_time')
        ->notExists('field_end_time')
        ->exists('field_teammate')
        ->execute();
    }
    catch (\Throwable $e) {
      return 0;
    }
    $activeUids = $openUids = [];
    if (!empty($activeTodayIds)) {
      foreach ($this->em->getStorage('wo_time_clock')->loadMultiple($activeTodayIds) as $e) {
        $activeUids[(int) $e->get('field_teammate')->target_id] = TRUE;
      }
    }
    if (!empty($openIds)) {
      foreach ($this->em->getStorage('wo_time_clock')->loadMultiple($openIds) as $e) {
        $openUids[(int) $e->get('field_teammate')->target_id] = TRUE;
      }
    }
    return count(array_diff_key($activeUids, $openUids));
  }

  /** Total active anomaly count across all 5 types since boundary. */
  protected function getTotalActiveAnomalyCount(): int {
    $boundary = $this->compensableHours->getDataQualityBoundary()->format('Y-m-d');
    $count = 0;
    foreach (array_keys($this->anomalyDetection->getAnomalyTypes()) as $type) {
      foreach ($this->anomalyDetection->findAnomaliesByType($type) as $entry) {
        $start = $entry->hasField('field_start_time') && !$entry->get('field_start_time')->isEmpty()
          ? substr((string) $entry->get('field_start_time')->value, 0, 10)
          : NULL;
        // Forgotten clock-outs without sensible start_time count as active.
        if ($start === NULL || $start >= $boundary) {
          $count++;
        }
      }
    }
    return $count;
  }

  /**
   * Team-wide average productive % across active teammates over the
   * last $days days. Returns NULL when no teammate has activity.
   */
  public function getTeamAvgProductivePercent(int $days = 7): ?float {
    $end = date('Y-m-d');
    $start = date('Y-m-d', strtotime("-$days days"));

    $teammates = $this->getActiveTeammates();
    $perUserPcts = [];
    foreach ($teammates as $user) {
      $uid = (int) $user->id();
      $totalComp = $totalWo = 0.0;
      foreach ($this->datesBetween($start, $end) as $date) {
        if (!$this->compensableHours->hasWoActivityOnDate($uid, $date)) {
          continue;
        }
        $totalComp += $this->compensableHours->getCompensableHoursForUserOnDate($uid, $date);
        $totalWo += $this->compensableHours->getWoHoursForUserOnDate($uid, $date);
      }
      if ($totalComp > 0) {
        $perUserPcts[] = ($totalWo / $totalComp) * 100.0;
      }
    }
    if (empty($perUserPcts)) {
      return NULL;
    }
    return round(array_sum($perUserPcts) / count($perUserPcts), 1);
  }

  /**
   * Lowest-productivity teammate over the last $days days.
   *
   * @return array{name: string, pct: float, uid: int}
   */
  public function getLowestProductivityTeammate(int $days = 30): array {
    $boundary = $this->compensableHours->getDataQualityBoundary()->format('Y-m-d');
    $candidate = date('Y-m-d', strtotime("-$days days"));
    $start = ($candidate < $boundary) ? $boundary : $candidate;
    $end = date('Y-m-d');

    $worst = ['name' => '', 'pct' => INF, 'uid' => 0];
    foreach ($this->getActiveTeammates() as $user) {
      $uid = (int) $user->id();
      $totalComp = $totalWo = 0.0;
      $hadAny = FALSE;
      foreach ($this->datesBetween($start, $end) as $date) {
        if (!$this->compensableHours->hasWoActivityOnDate($uid, $date)) {
          continue;
        }
        $hadAny = TRUE;
        $totalComp += $this->compensableHours->getCompensableHoursForUserOnDate($uid, $date);
        $totalWo += $this->compensableHours->getWoHoursForUserOnDate($uid, $date);
      }
      if (!$hadAny || $totalComp <= 0) {
        continue;
      }
      $pct = ($totalWo / $totalComp) * 100.0;
      if ($pct < $worst['pct']) {
        $worst = ['name' => $user->getDisplayName(), 'pct' => $pct, 'uid' => $uid];
      }
    }
    return $worst['uid'] > 0 ? $worst : ['name' => '', 'pct' => 0.0, 'uid' => 0];
  }

  // ──────────────────────────────────────────────────────────────────────
  // INTERNAL HELPERS
  // ──────────────────────────────────────────────────────────────────────

  /** @return EntityInterface[] */
  protected function getActiveTeammates(): array {
    try {
      $uids = $this->em->getStorage('user')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition('roles', 'teammates')
        ->execute();
    }
    catch (\Throwable $e) {
      return [];
    }
    return $uids ? array_values($this->em->getStorage('user')->loadMultiple($uids)) : [];
  }

  protected function todayRangeUtc(): array {
    $localTz = new \DateTimeZone(date_default_timezone_get());
    $utc = new \DateTimeZone('UTC');
    $start = (new \DateTime('today 00:00:00', $localTz))->setTimezone($utc);
    $end = (new \DateTime('today 23:59:59', $localTz))->setTimezone($utc);
    return [$start->format('Y-m-d\TH:i:s'), $end->format('Y-m-d\TH:i:s')];
  }

  protected function datesBetween(string $startDate, string $endDate): \Generator {
    $tz = new \DateTimeZone(date_default_timezone_get());
    $cur = new \DateTime($startDate, $tz);
    $end = new \DateTime($endDate, $tz);
    while ($cur <= $end) {
      yield $cur->format('Y-m-d');
      $cur->modify('+1 day');
    }
  }

  /**
   * Display a 'Y-m-d' (or fuller) date string as MM/DD/YYYY for the
   * US-facing UI. Returns the input unchanged on parse failure.
   */
  protected function formatDateUs(string $date): string {
    if ($date === '' || $date === '—') {
      return $date;
    }
    try {
      return (new \DateTime(substr($date, 0, 10)))->format('m/d/Y');
    }
    catch (\Throwable $e) {
      return $date;
    }
  }

}
