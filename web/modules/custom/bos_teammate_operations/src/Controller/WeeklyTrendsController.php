<?php

declare(strict_types=1);

namespace Drupal\bos_teammate_operations\Controller;

use Drupal\bos_teammate_operations\Service\CompensableHoursService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Weekly Trends view (Phase 2F).
 *
 * Path: /admin/office/operations/teammates/trends
 *
 * Per-teammate productivity patterns over rolling 4-week and 8-week
 * windows. Spot trends — improving, steady, declining — rather than
 * reacting to single bad days. Read-only against existing wo_time_clock
 * data via the shared CompensableHoursService primitives; no service
 * swaps, no auto-refresh, no caching, no JS chart library.
 *
 * Each row covers one active teammate (role=teammates, status=1). The
 * 8 weekly cells show the most recent 8 completed Mon-Sun weeks ordered
 * oldest → newest (W-8 on the left, W-1 on the right). The current
 * (partial) week is intentionally excluded so every cell has the same
 * 7-day weight.
 *
 * Trend classification — compares the 4-week recent average to the
 * 8-week long-window average:
 *
 *   diff = avg4 - avg8
 *   diff >=  +TREND_DELTA_PP → ↑ improving (green)
 *   diff <=  -TREND_DELTA_PP → ↓ declining (red)
 *   otherwise                → → steady    (gray)
 *
 * Teammates with no activity at all across the 8-week window are
 * excluded entirely. A teammate with activity in the older half but
 * none in the most recent 4 weeks is shown as "inactive" (the
 * declining-trend classification doesn't apply because it would mix
 * "actually trending down" with "stopped working".)
 *
 * Same role gate as the rest of the variance suite.
 */
final class WeeklyTrendsController extends ControllerBase implements ContainerInjectionInterface {

  /** How many recent weeks to show in the cell strip. */
  private const WEEKS_SHOWN = 8;

  /** Recent-window size for the 4-week vs 8-week trend comparison. */
  private const RECENT_WEEKS = 4;

  /**
   * Threshold (percentage points) between 4-week and 8-week averages
   * that flips the trend classification. 10pp is wide enough to filter
   * routine wiggles but tight enough to catch a sustained shift.
   *
   * Hardcoded constant rather than business_setting — analogous to the
   * hub's TEAM_AVG_RED / TEAM_AVG_YELLOW. Operators rarely need to
   * adjust trend thresholds; promoting them to config would invite
   * confusion with the per-day variance bands.
   */
  private const TREND_DELTA_PP = 10.0;

  /** Productivity % bands for the cell color coding. */
  private const CELL_RED_MAX    = 50.0;
  private const CELL_YELLOW_MAX = 70.0;

  /**
   * Per-request memoization of department resolution (keyed by uid).
   * @var array<int, array{0: string, 1: int[]}>
   */
  private array $deptCache = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $em,
    private readonly DateFormatterInterface $dateFmt,
    private readonly FormBuilderInterface $forms,
    private readonly CompensableHoursService $compensableHours,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('form_builder'),
      $container->get('bos_teammate_operations.compensable_hours'),
    );
  }

  // ──────────────────────────────────────────────────────────────────────
  // MAIN BUILD
  // ──────────────────────────────────────────────────────────────────────

  public function build(Request $request): array {
    $deptFilter = (string) ($request->query->get('department') ?: 'all');
    $sort = (string) ($request->query->get('sort') ?: 'trend_worst_first');
    $groupBy = (bool) $request->query->get('group_by_department');

    $build = [];
    $build['#attached']['library'][] = 'bos_teammate_operations/variance_dashboard';

    $build['header'] = $this->buildHeader();
    $build['filter_form'] = $this->forms->getForm(
      'Drupal\bos_teammate_operations\Form\WeeklyTrendsFilterForm',
      [
        'department' => $deptFilter,
        'sort' => $sort,
        'group_by_department' => $groupBy,
      ],
    );
    $build['table'] = $this->buildTrendsTable($deptFilter, $sort, $groupBy);
    $build['footer'] = $this->buildBoundaryFooter();

    return $build;
  }

  // ──────────────────────────────────────────────────────────────────────
  // HEADER + FOOTER
  // ──────────────────────────────────────────────────────────────────────

  protected function buildHeader(): array {
    $hubUrl = Url::fromRoute('bos_teammate_operations.hub')->toString();
    $weekLabels = $this->getWeekStartLabels();
    $oldestLabel = $weekLabels[0] ?? '';
    $newestLabel = end($weekLabels) ?: '';
    $html = '<div class="bos-hub-header bos-weekly-trends-header">'
      . '<p><a href="' . htmlspecialchars($hubUrl) . '">← Back to Teammate Operations Hub</a></p>'
      . '<h1>Weekly Trends</h1>'
      . '<p class="bos-hub-subtitle">Per-teammate productivity over the most recent ' . self::WEEKS_SHOWN
      . ' completed weeks. Each cell is one Monday-Sunday week. The current (partial) week is excluded.</p>'
      . '<p class="bos-hub-timestamp">Window: ' . htmlspecialchars($oldestLabel) . ' – ' . htmlspecialchars($newestLabel) . '</p>'
      . '</div>';
    return ['#markup' => $html, '#allowed_tags' => ['div', 'h1', 'p', 'a']];
  }

  protected function buildBoundaryFooter(): array {
    $boundary = $this->compensableHours->getDataQualityBoundary()->format('Y-m-d');
    $html = '<p class="bos-hub-boundary-footer">Data quality boundary: '
      . htmlspecialchars($this->formatDateUs($boundary))
      . '. Pre-boundary weekly cells are blank — those weeks are excluded from default views.</p>';
    return ['#markup' => $html, '#allowed_tags' => ['p']];
  }

  // ──────────────────────────────────────────────────────────────────────
  // TABLE
  // ──────────────────────────────────────────────────────────────────────

  protected function buildTrendsTable(string $deptFilter, string $sort, bool $groupBy): array {
    $rows = $this->getTrendRows($deptFilter);

    if (empty($rows)) {
      return [
        '#markup' => '<p class="bos-active-empty">No active teammates with activity in the last '
          . self::WEEKS_SHOWN . ' weeks.</p>',
        '#allowed_tags' => ['p'],
      ];
    }

    $this->sortRows($rows, $sort);

    $weekLabels = $this->getWeekStartLabels();
    $weekHeaders = [];
    foreach ($weekLabels as $i => $label) {
      // W-8 on the left, W-1 on the right (the array is oldest→newest).
      $offset = count($weekLabels) - $i;
      $weekHeaders[] = 'W-' . $offset . '<br><small>' . htmlspecialchars($label) . '</small>';
    }
    $headers = array_merge(
      ['Teammate'],
      $weekHeaders,
      ['4-wk', '8-wk', 'Trend']
    );

    $renderRow = fn (array $row): string => $this->renderRow($row);

    if ($groupBy) {
      $html = $this->renderGroupedTable($rows, $headers, $renderRow);
    }
    else {
      $html = $this->renderFlatTable($rows, $headers, $renderRow);
    }

    return [
      '#markup' => $html,
      '#allowed_tags' => ['h2', 'h3', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
                          'a', 'span', 'p', 'div', 'br', 'small'],
    ];
  }

  /**
   * Build one row per active teammate with any 8-week activity.
   *
   * @return array<int, array<string, mixed>>
   */
  protected function getTrendRows(string $deptFilter): array {
    $teammates = $this->getActiveTeammates();
    $deptId = ($deptFilter !== 'all' && $deptFilter !== '' && ctype_digit($deptFilter)) ? (int) $deptFilter : 0;

    $rows = [];
    foreach ($teammates as $user) {
      $uid = (int) $user->id();
      [$deptLabel, $deptIds] = $this->resolveDepartment($user);
      if ($deptId > 0 && !in_array($deptId, $deptIds, TRUE)) {
        continue;
      }

      $weekly = $this->compensableHours->getWeeklyProductivePercents($uid, self::WEEKS_SHOWN);
      // Skip teammates with no activity in any of the 8 weeks.
      $nonNullCount = count(array_filter($weekly, static fn ($v) => $v !== NULL));
      if ($nonNullCount === 0) {
        continue;
      }

      $weeklyValues = array_values($weekly); // oldest → newest
      $recent = array_slice($weeklyValues, -self::RECENT_WEEKS); // last 4 weeks
      $avg4 = $this->avg($recent);
      $avg8 = $this->avg($weeklyValues);
      $trend = $this->classifyTrend($avg4, $avg8, $recent);

      $rows[] = [
        'uid' => $uid,
        'teammate_label' => $user->getDisplayName(),
        'dept_label' => $deptLabel,
        'weekly' => $weeklyValues,
        'avg4' => $avg4,
        'avg8' => $avg8,
        'trend' => $trend,
      ];
    }
    return $rows;
  }

  protected function renderRow(array $row): string {
    $detailUrl = Url::fromRoute('bos_teammate_operations.variance_teammate_detail', ['user' => $row['uid']])->toString();
    $teammateLink = '<a href="' . htmlspecialchars($detailUrl) . '">' . htmlspecialchars($row['teammate_label']) . '</a>';

    $cells = '';
    foreach ($row['weekly'] as $pct) {
      $cells .= $this->renderWeeklyCell($pct);
    }

    $avg4Cell = $this->renderAvgCell($row['avg4']);
    $avg8Cell = $this->renderAvgCell($row['avg8']);
    $trendCell = $this->renderTrendCell($row['trend']);

    return '<tr>'
      . '<td class="bos-trend-teammate">' . $teammateLink . '</td>'
      . $cells
      . $avg4Cell
      . $avg8Cell
      . $trendCell
      . '</tr>';
  }

  protected function renderWeeklyCell(?float $pct): string {
    if ($pct === NULL) {
      return '<td class="bos-trend-cell bos-trend-cell-na" title="No activity this week">—</td>';
    }
    $class = $this->cellClassForPct($pct);
    return '<td class="bos-trend-cell ' . $class . '" title="' . htmlspecialchars(number_format($pct, 1) . '%') . '">'
      . htmlspecialchars((string) (int) round($pct))
      . '</td>';
  }

  protected function renderAvgCell(?float $pct): string {
    if ($pct === NULL) {
      return '<td class="bos-trend-avg bos-trend-cell-na">—</td>';
    }
    $class = $this->cellClassForPct($pct);
    return '<td class="bos-trend-avg ' . $class . '">' . htmlspecialchars(number_format($pct, 1) . '%') . '</td>';
  }

  protected function renderTrendCell(string $trend): string {
    return match ($trend) {
      'improving' => '<td class="bos-trend-arrow bos-trend-up">↑ improving</td>',
      'declining' => '<td class="bos-trend-arrow bos-trend-down">↓ declining</td>',
      'inactive'  => '<td class="bos-trend-arrow bos-trend-inactive">⏸ inactive (4wk)</td>',
      default     => '<td class="bos-trend-arrow bos-trend-steady">→ steady</td>',
    };
  }

  // ──────────────────────────────────────────────────────────────────────
  // SORTING
  // ──────────────────────────────────────────────────────────────────────

  protected function sortRows(array &$rows, string $sort): void {
    switch ($sort) {
      case 'name_asc':
        usort($rows, fn ($a, $b) => strcasecmp($a['teammate_label'], $b['teammate_label']));
        break;

      case '4wk_asc':
        usort($rows, fn ($a, $b) => $this->compareNullableFloat($a['avg4'], $b['avg4']));
        break;

      case '8wk_asc':
        usort($rows, fn ($a, $b) => $this->compareNullableFloat($a['avg8'], $b['avg8']));
        break;

      case 'trend_worst_first':
      default:
        // Decliners first, then inactive, then steady, then improvers.
        // Within decliners, biggest drop first (most-negative diff).
        usort($rows, function ($a, $b) {
          $rank = ['declining' => 0, 'inactive' => 1, 'steady' => 2, 'improving' => 3];
          $ra = $rank[$a['trend']] ?? 9;
          $rb = $rank[$b['trend']] ?? 9;
          if ($ra !== $rb) return $ra <=> $rb;
          // Same trend bucket: secondary sort by 4wk avg ascending
          // (worst-performing first).
          return $this->compareNullableFloat($a['avg4'], $b['avg4']);
        });
        break;
    }
  }

  protected function compareNullableFloat(?float $a, ?float $b): int {
    // NULLs sort to the end regardless of direction.
    if ($a === NULL && $b === NULL) return 0;
    if ($a === NULL) return 1;
    if ($b === NULL) return -1;
    return $a <=> $b;
  }

  // ──────────────────────────────────────────────────────────────────────
  // TABLE RENDERERS (same shape as ActiveNowController for visual parity)
  // ──────────────────────────────────────────────────────────────────────

  protected function renderFlatTable(array $rows, array $headers, callable $rowRenderer): string {
    $html = '<table class="bos-weekly-trends-table">';
    $html .= '<thead><tr>';
    foreach ($headers as $h) {
      $html .= '<th>' . $h . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
      $html .= $rowRenderer($row);
    }
    $html .= '</tbody></table>';
    return $html;
  }

  protected function renderGroupedTable(array $rows, array $headers, callable $rowRenderer): string {
    $groups = [];
    foreach ($rows as $row) {
      $groups[$row['dept_label']][] = $row;
    }
    ksort($groups);
    $html = '';
    foreach ($groups as $deptLabel => $deptRows) {
      $html .= '<h3 class="bos-active-dept-heading">'
        . htmlspecialchars($deptLabel)
        . ' <span class="bos-active-dept-count">(' . count($deptRows) . ')</span></h3>';
      $html .= $this->renderFlatTable($deptRows, $headers, $rowRenderer);
    }
    return $html;
  }

  // ──────────────────────────────────────────────────────────────────────
  // CLASSIFICATION HELPERS
  // ──────────────────────────────────────────────────────────────────────

  /**
   * Trend classification by 4-week vs 8-week comparison.
   *
   * Special case: if the recent 4 weeks have no activity at all (all
   * NULL), the teammate is labeled "inactive" rather than "declining"
   * — a real trend signal requires at least one recent data point.
   *
   * @param float|null $avg4
   * @param float|null $avg8
   * @param array<int, float|null> $recentWeeklies
   *   The 4-week tail, used to detect the all-NULL inactive case.
   *
   * @return string  'improving' | 'declining' | 'steady' | 'inactive'
   */
  protected function classifyTrend(?float $avg4, ?float $avg8, array $recentWeeklies): string {
    $recentNonNull = count(array_filter($recentWeeklies, static fn ($v) => $v !== NULL));
    if ($recentNonNull === 0) {
      return 'inactive';
    }
    if ($avg4 === NULL || $avg8 === NULL) {
      return 'steady';
    }
    $diff = $avg4 - $avg8;
    if ($diff >= self::TREND_DELTA_PP)  return 'improving';
    if ($diff <= -self::TREND_DELTA_PP) return 'declining';
    return 'steady';
  }

  /**
   * Average of an array, skipping NULLs. Returns NULL when no numbers.
   *
   * @param array<int, float|null> $values
   */
  protected function avg(array $values): ?float {
    $nums = array_filter($values, static fn ($v) => $v !== NULL);
    if (!$nums) return NULL;
    return round(array_sum($nums) / count($nums), 1);
  }

  protected function cellClassForPct(float $pct): string {
    if ($pct < self::CELL_RED_MAX)    return 'bos-variance-red';
    if ($pct < self::CELL_YELLOW_MAX) return 'bos-variance-yellow';
    return 'bos-variance-green';
  }

  // ──────────────────────────────────────────────────────────────────────
  // RESOLUTION (lifted from ActiveNowController; same shape)
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

  protected function resolveDepartment(UserInterface $user): array {
    $uid = (int) $user->id();
    if (isset($this->deptCache[$uid])) {
      return $this->deptCache[$uid];
    }
    $profiles = $this->em->getStorage('profile')->loadByProperties([
      'uid' => $uid,
      'type' => 'teammate_profile',
    ]);
    $labels = [];
    $ids = [];
    foreach ($profiles as $profile) {
      if (!$profile->hasField('field_assigned_crew') || $profile->get('field_assigned_crew')->isEmpty()) {
        continue;
      }
      foreach ($profile->get('field_assigned_crew')->referencedEntities() as $crew) {
        $labels[] = $crew->label();
        $ids[] = (int) $crew->id();
      }
    }
    return $this->deptCache[$uid] = [$labels ? implode(', ', $labels) : '—', $ids];
  }

  // ──────────────────────────────────────────────────────────────────────
  // WEEK-LABEL HELPERS
  // ──────────────────────────────────────────────────────────────────────

  /**
   * Return the same week-start dates the service would compute, formatted
   * for column headers (e.g., "05/12"). Order matches the service's
   * oldest → newest array.
   *
   * @return array<int, string>
   */
  protected function getWeekStartLabels(): array {
    // Use the service to get the canonical week list, then strip values.
    // (We can run this against any active uid because the service returns
    // the same week keys regardless of user — values vary, keys don't.)
    // Fall back to local computation if no active teammates exist.
    $sampleUid = $this->firstActiveTeammateUid();
    if ($sampleUid > 0) {
      $weekly = $this->compensableHours->getWeeklyProductivePercents($sampleUid, self::WEEKS_SHOWN);
      $keys = array_keys($weekly);
    }
    else {
      $keys = $this->computeWeekStartsFallback();
    }
    return array_map(fn ($d) => $this->formatShortDate($d), $keys);
  }

  /**
   * Local fallback when no teammates exist (mainly for the header during
   * a fresh DB or empty filter). Mirrors the service's anchoring logic.
   *
   * @return string[]  Week-start (Monday) Y-m-d strings, oldest first.
   */
  protected function computeWeekStartsFallback(): array {
    $tz = new \DateTimeZone(date_default_timezone_get());
    $today = new \DateTime('today', $tz);
    $dow = (int) $today->format('N');
    $lastSunday = ($dow === 7) ? clone $today : (clone $today)->modify('-' . $dow . ' days');
    $out = [];
    for ($i = 0; $i < self::WEEKS_SHOWN; $i++) {
      $sun = (clone $lastSunday)->modify('-' . (7 * $i) . ' days');
      $mon = (clone $sun)->modify('-6 days');
      $out[] = $mon->format('Y-m-d');
    }
    return array_reverse($out);
  }

  protected function firstActiveTeammateUid(): int {
    try {
      $uids = $this->em->getStorage('user')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition('roles', 'teammates')
        ->range(0, 1)
        ->execute();
    }
    catch (\Throwable $e) {
      return 0;
    }
    return $uids ? (int) reset($uids) : 0;
  }

  // ──────────────────────────────────────────────────────────────────────
  // FORMATTERS
  // ──────────────────────────────────────────────────────────────────────

  /** MM/DD only — column-header friendly. */
  protected function formatShortDate(string $ymd): string {
    $dt = \DateTime::createFromFormat('Y-m-d', $ymd);
    return $dt ? $dt->format('m/d') : $ymd;
  }

  /** MM/DD/YYYY in site default timezone (boundary footer). */
  protected function formatDateUs(string $ymd): string {
    $dt = \DateTime::createFromFormat('Y-m-d', $ymd);
    return $dt ? $dt->format('m/d/Y') : $ymd;
  }

}
