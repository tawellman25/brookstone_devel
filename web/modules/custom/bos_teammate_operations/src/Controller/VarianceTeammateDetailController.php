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
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Per-teammate variance detail page (Phase 2C).
 *
 * Path: /admin/office/operations/teammates/variance/{user}
 *
 * Single-teammate, day-by-day breakdown for the selected window.
 * Reads only — never mutates wo_time_clock. Inherits boundary
 * defaults from CompensableHoursService::getDataQualityBoundary().
 */
final class VarianceTeammateDetailController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly CompensableHoursService $compensableHours,
    private readonly AnomalyDetectionService $anomalyDetection,
    private readonly EntityTypeManagerInterface $em,
    private readonly FormBuilderInterface $forms,
    private readonly DateFormatterInterface $dateFmt,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('bos_teammate_operations.compensable_hours'),
      $container->get('bos_teammate_operations.anomaly_detection'),
      $container->get('entity_type.manager'),
      $container->get('form_builder'),
      $container->get('date.formatter'),
    );
  }

  public function getPageTitle(UserInterface $user): string {
    return 'Variance Detail: ' . $user->getDisplayName();
  }

  // ──────────────────────────────────────────────────────────────────────
  // MAIN PAGE
  // ──────────────────────────────────────────────────────────────────────

  public function build(UserInterface $user, Request $request): array {
    $boundary = $this->compensableHours->getDataQualityBoundary();
    $boundaryStr = $boundary->format('Y-m-d');

    // ── filters ────────────────────────────────────────────────────────
    $startQuery = $request->query->get('start_date');
    if ($startQuery) {
      $start = $startQuery;
    }
    else {
      $candidate = date('Y-m-d', strtotime('-30 days'));
      $start = ($candidate < $boundaryStr) ? $boundaryStr : $candidate;
    }
    $end = $request->query->get('end_date') ?: date('Y-m-d');
    $showAnomaliesOnly = (bool) $request->query->get('show_anomalies_only');
    $showActivityOnly = (bool) $request->query->get('show_activity_only');
    $preBoundary = ($start < $boundaryStr);

    // ── filter form ────────────────────────────────────────────────────
    $form = $this->forms->getForm(
      'Drupal\bos_teammate_operations\Form\VarianceTeammateDetailFilterForm',
      [
        'uid'                  => (int) $user->id(),
        'start_date'           => $start,
        'end_date'             => $end,
        'show_anomalies_only'  => $showAnomaliesOnly,
        'show_activity_only'   => $showActivityOnly,
        'boundary_date'        => $boundaryStr,
      ]
    );

    // ── render ─────────────────────────────────────────────────────────
    $build = [];
    $build['#attached']['library'][] = 'bos_teammate_operations/variance_dashboard';

    $build['header'] = $this->buildHeader($user, $start, $end);
    $build['filters'] = $form;

    if ($preBoundary) {
      $build['boundary_warning'] = [
        '#markup' => '<div class="bos-variance-boundary-warning">'
          . $this->t(
            '⚠ You are viewing data from before the data quality boundary (<strong>@b</strong>). Variance numbers may be unreliable due to inconsistent time clock discipline before this date. Adjust the start date to <strong>@b</strong> or later for reliable data.',
            ['@b' => $boundaryStr]
          )->render()
          . '</div>',
        '#allowed_tags' => ['div', 'strong'],
      ];
    }

    $build['summary'] = $this->buildSummarySection($user, $start, $end);
    $build['daily'] = $this->buildDailyTable($user, $start, $end, [
      'anomalies_only' => $showAnomaliesOnly,
      'activity_only'  => $showActivityOnly,
    ]);

    return $build;
  }

  // ──────────────────────────────────────────────────────────────────────
  // HEADER
  // ──────────────────────────────────────────────────────────────────────

  protected function buildHeader(UserInterface $user, string $start, string $end): array {
    // Department label — same crew_types lookup the rollup uses.
    $deptLabel = $this->userDepartmentLabel($user);

    // Roles — strip the always-present "authenticated" so the badges
    // are meaningful.
    $roles = array_filter($user->getRoles(), fn(string $r) => $r !== 'authenticated');
    $roleBadges = '';
    foreach ($roles as $role) {
      $roleBadges .= '<span class="bos-role-badge">' . htmlspecialchars($role) . '</span> ';
    }

    $rollupUrl = Url::fromRoute('bos_teammate_operations.variance_daily', [], [
      'query' => array_filter(['start_date' => $start, 'end_date' => $end]),
    ])->toString();
    $editUrl = $user->toUrl('edit-form')->toString();

    return [
      '#markup' => '<div class="bos-teammate-detail-header">'
        . '<h1 class="bos-teammate-detail-name">' . htmlspecialchars($user->getDisplayName()) . '</h1>'
        . '<div class="bos-teammate-detail-meta">'
        . '<span class="bos-teammate-detail-dept"><strong>Department:</strong> ' . htmlspecialchars($deptLabel) . '</span>'
        . '<span class="bos-teammate-detail-roles">' . ($roleBadges ?: '<em>(no operational roles)</em>') . '</span>'
        . '</div>'
        . '<div class="bos-teammate-detail-quicklinks">'
        . '<a href="' . htmlspecialchars($rollupUrl) . '">← Back to Variance Rollup</a>'
        . ' &middot; '
        . '<a href="' . htmlspecialchars($editUrl) . '">View User Profile ↗</a>'
        . '</div>'
        . '</div>',
      '#allowed_tags' => ['div', 'h1', 'span', 'strong', 'em', 'a'],
    ];
  }

  protected function userDepartmentLabel(UserInterface $user): string {
    $profile_storage = $this->em->getStorage('profile');
    $profiles = $profile_storage->loadByProperties([
      'uid' => $user->id(),
      'type' => 'teammate_profile',
    ]);
    $labels = [];
    foreach ($profiles as $profile) {
      if (!$profile->hasField('field_assigned_crew') || $profile->get('field_assigned_crew')->isEmpty()) {
        continue;
      }
      foreach ($profile->get('field_assigned_crew')->referencedEntities() as $crew) {
        $labels[] = $crew->label();
      }
    }
    return $labels ? implode(', ', $labels) : '—';
  }

  // ──────────────────────────────────────────────────────────────────────
  // SUMMARY SECTION
  // ──────────────────────────────────────────────────────────────────────

  protected function buildSummarySection(UserInterface $user, string $start, string $end): array {
    $uid = (int) $user->id();
    $totalDays = 0;
    $daysActive = 0;
    $totalComp = 0.0;
    $totalWo = 0.0;
    $bestDate = NULL; $bestPct = -INF;
    $worstDate = NULL; $worstPct = INF;

    foreach ($this->datesBetween($start, $end) as $date) {
      $totalDays++;
      $hadActivity = $this->compensableHours->hasWoActivityOnDate($uid, $date);
      if (!$hadActivity) {
        continue;
      }
      $daysActive++;
      $comp = $this->compensableHours->getCompensableHoursForUserOnDate($uid, $date);
      $wo = $this->compensableHours->getWoHoursForUserOnDate($uid, $date);
      $totalComp += $comp;
      $totalWo += $wo;
      if ($comp > 0) {
        $pct = ($wo / $comp) * 100.0;
        if ($pct > $bestPct) { $bestPct = $pct; $bestDate = $date; }
        if ($pct < $worstPct) { $worstPct = $pct; $worstDate = $date; }
      }
    }

    $totalVar = round($totalComp - $totalWo, 2);
    $avgPct = $totalComp > 0 ? round(($totalWo / $totalComp) * 100.0, 1) : NULL;
    $varStatus = $this->compensableHours->getVarianceStatus(
      $daysActive > 0 ? $totalVar / max(1, $daysActive) : 0.0,
      $daysActive > 0
    );
    $pctStatus = $varStatus;
    $anomalyCount = $this->anomalyDetection->countAnomaliesForUser($uid, $start, $end, TRUE);

    $cards = [
      ['label' => 'Days w/ Activity', 'value' => "$daysActive of $totalDays", 'class' => ''],
      ['label' => 'Total Compensable',  'value' => $this->fmtHrs($totalComp), 'class' => ''],
      ['label' => 'Total WO',  'value' => $this->fmtHrs($totalWo), 'class' => ''],
      ['label' => 'Total Variance', 'value' => $this->fmtHrs($totalVar), 'class' => 'bos-variance-' . $varStatus],
      ['label' => 'Avg Productive %', 'value' => $avgPct === NULL ? '—' : $avgPct . '%', 'class' => 'bos-variance-' . $pctStatus],
      ['label' => 'Best Day', 'value' => $bestDate ? "$bestDate (" . round($bestPct, 1) . '%)' : '—', 'class' => ''],
      ['label' => 'Worst Day', 'value' => $worstDate ? "$worstDate (" . round($worstPct, 1) . '%)' : '—', 'class' => ''],
      ['label' => 'Active Anomalies', 'value' => (string) $anomalyCount, 'class' => $anomalyCount > 0 ? 'bos-stat-warn' : ''],
    ];

    $html = '<div class="bos-stat-grid">';
    foreach ($cards as $c) {
      $html .= '<div class="bos-stat-card ' . $c['class'] . '">'
        . '<div class="bos-stat-label">' . htmlspecialchars($c['label']) . '</div>'
        . '<div class="bos-stat-value">' . htmlspecialchars((string) $c['value']) . '</div>'
        . '</div>';
    }
    $html .= '</div>';

    return [
      '#markup' => $html,
      '#allowed_tags' => ['div'],
    ];
  }

  // ──────────────────────────────────────────────────────────────────────
  // DAILY TABLE
  // ──────────────────────────────────────────────────────────────────────

  protected function buildDailyTable(UserInterface $user, string $start, string $end, array $filters): array {
    $uid = (int) $user->id();
    $rows = [];
    $dates = iterator_to_array($this->datesBetween($start, $end));
    rsort($dates); // most recent first

    foreach ($dates as $date) {
      $hadActivity = $this->compensableHours->hasWoActivityOnDate($uid, $date);
      $entries = $hadActivity ? $this->getWoEntriesForUserOnDate($uid, $date) : [];

      $anomalyCount = 0;
      foreach ($entries as $entry) {
        if (!empty($this->anomalyDetection->detectAnomalies($entry))) {
          $anomalyCount++;
        }
      }

      // Filters.
      if (!empty($filters['anomalies_only']) && $anomalyCount === 0) {
        continue;
      }
      if (!empty($filters['activity_only']) && !$hadActivity) {
        continue;
      }

      $comp = $hadActivity ? $this->compensableHours->getCompensableHoursForUserOnDate($uid, $date) : 0.0;
      $wo = $hadActivity ? $this->compensableHours->getWoHoursForUserOnDate($uid, $date) : 0.0;
      $variance = round($comp - $wo, 2);
      $status = $this->compensableHours->getVarianceStatus($variance, $hadActivity);
      $productPct = $comp > 0 ? round(($wo / $comp) * 100.0, 1) : NULL;
      $woTouched = $this->countDistinctWosOnDate($entries);

      // Date label: "Mon 2026-04-14"
      $dateLabel = (new \DateTime($date))->format('D Y-m-d');

      $expandedHtml = $hadActivity
        ? $this->renderWoEntriesExpansion($entries)
        : '';
      $dateCellHtml = $expandedHtml
        ? '<details><summary>' . htmlspecialchars($dateLabel) . '</summary>' . $expandedHtml . '</details>'
        : htmlspecialchars($dateLabel);

      $rows[] = [
        ['data' => ['#markup' => $dateCellHtml]],
        ['data' => $hadActivity ? '✓' : '', 'class' => ['bos-numeric']],
        ['data' => $hadActivity ? $this->fmtHrs($comp) : '0.00', 'class' => ['bos-numeric']],
        ['data' => $hadActivity ? $this->fmtHrs($wo) : '0.00', 'class' => ['bos-numeric']],
        ['data' => $hadActivity ? $this->fmtHrs($variance) : '—', 'class' => ['bos-numeric', 'bos-variance-' . $status]],
        ['data' => $productPct === NULL ? '—' : $productPct . '%', 'class' => ['bos-numeric', 'bos-variance-' . $status]],
        ['data' => $hadActivity ? (string) $woTouched : '—', 'class' => ['bos-numeric']],
        ['data' => ['#markup' => $anomalyCount > 0 ? '<span class="bos-anomaly-icon" title="' . $anomalyCount . ' anomaly row(s)">⚠</span>' : ''], 'class' => ['bos-numeric']],
      ];
    }

    if (empty($rows)) {
      return [
        '#markup' => '<div class="bos-variance-empty">'
          . $this->t('No matching days for this teammate.')->render()
          . '</div>',
      ];
    }

    return [
      '#type' => 'table',
      '#header' => ['Date', 'Activity', 'Comp Hrs', 'WO Hrs', 'Variance', 'Productive %', 'WOs Touched', 'Anomaly'],
      '#rows' => $rows,
      '#attributes' => ['class' => ['bos-variance-table']],
    ];
  }

  /**
   * Build the inner sub-table HTML for an expanded day's WO entries.
   */
  protected function renderWoEntriesExpansion(array $entries): string {
    $html = '<table class="bos-wo-subtable">';
    $html .= '<thead><tr>'
      . '<th>WO</th><th>Title</th><th>Start</th><th>End</th><th>Hrs</th><th>Anomaly</th>'
      . '</tr></thead><tbody>';
    foreach ($entries as $entry) {
      $html .= '<tr>' . $this->buildWoEntryRowCells($entry) . '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
  }

  protected function buildWoEntryRowCells(EntityInterface $entry): string {
    $woId = $woTitle = '—';
    if ($entry->hasField('field_work_order') && !$entry->get('field_work_order')->isEmpty()) {
      $wo = $entry->get('field_work_order')->entity;
      if ($wo) {
        $woId = (int) $wo->id();
        $woTitle = $wo->label() ?: ('WO #' . $woId);
        $woUrl = Url::fromUri('internal:/work_order/' . $woId)->toString();
        $woId = '<a href="' . htmlspecialchars($woUrl) . '">' . $woId . '</a>';
      }
    }

    $start = $entry->hasField('field_start_time') && !$entry->get('field_start_time')->isEmpty()
      ? $this->fmtTime((string) $entry->get('field_start_time')->value)
      : '—';
    $end = $entry->hasField('field_end_time') && !$entry->get('field_end_time')->isEmpty()
      ? $this->fmtTime((string) $entry->get('field_end_time')->value)
      : '<span class="bos-open-label">OPEN ⚠</span>';
    $total = $entry->hasField('field_total_time') && !$entry->get('field_total_time')->isEmpty()
      ? number_format((float) $entry->get('field_total_time')->value, 2)
      : '—';

    $anomalies = $this->anomalyDetection->detectAnomalies($entry);
    $anomalyText = '';
    if (!empty($anomalies)) {
      $msgs = array_map(fn($a) => htmlspecialchars($a['message']), $anomalies);
      $anomalyText = '<span class="bos-anomaly-icon">⚠</span> ' . implode('; ', $msgs);
    }

    return '<td>' . $woId . '</td>'
      . '<td>' . htmlspecialchars((string) $woTitle) . '</td>'
      . '<td>' . $start . '</td>'
      . '<td>' . $end . '</td>'
      . '<td class="bos-numeric">' . $total . '</td>'
      . '<td>' . $anomalyText . '</td>';
  }

  /** Yields each Y-m-d date string from start to end (inclusive). */
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
   * Load closed wo_time_clock entries for a user on a given local date.
   *
   * @return EntityInterface[]
   */
  protected function getWoEntriesForUserOnDate(int $uid, string $date): array {
    $localTz = new \DateTimeZone(date_default_timezone_get());
    $utc = new \DateTimeZone('UTC');
    $start = new \DateTime($date . ' 00:00:00', $localTz);
    $end = new \DateTime($date . ' 23:59:59', $localTz);
    $start->setTimezone($utc);
    $end->setTimezone($utc);
    $startUtc = $start->format('Y-m-d\TH:i:s');
    $endUtc = $end->format('Y-m-d\TH:i:s');

    try {
      $ids = $this->em->getStorage('wo_time_clock')->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_teammate', $uid)
        ->condition('field_start_time', $startUtc, '>=')
        ->condition('field_start_time', $endUtc, '<=')
        ->exists('field_end_time')
        ->execute();
    }
    catch (\Throwable $e) {
      return [];
    }
    if (empty($ids)) {
      return [];
    }
    return array_values($this->em->getStorage('wo_time_clock')->loadMultiple($ids));
  }

  protected function countDistinctWosOnDate(array $entries): int {
    $woIds = [];
    foreach ($entries as $entry) {
      if ($entry->hasField('field_work_order') && !$entry->get('field_work_order')->isEmpty()) {
        $woIds[(int) $entry->get('field_work_order')->target_id] = TRUE;
      }
    }
    return count($woIds);
  }

  protected function fmtHrs(float $hours): string {
    return number_format($hours, 2);
  }

  /**
   * Render a stored UTC datetime string as local-tz "h:i AM/PM".
   */
  protected function fmtTime(string $utcStored): string {
    try {
      $dt = new \DateTime($utcStored, new \DateTimeZone('UTC'));
      $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
      return $dt->format('g:i A');
    }
    catch (\Throwable $e) {
      return htmlspecialchars($utcStored);
    }
  }

}
