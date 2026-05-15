<?php

declare(strict_types=1);

namespace Drupal\bos_teammate_operations\Controller;

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
 * Active Now view (Phase 2E).
 *
 * Path: /admin/office/operations/teammates/active
 *
 * Operational snapshot answering "right now, who is working on what?"
 * Two stacked sections — currently clocked in (Section 1) and today's
 * activity (Section 2). Read-only against existing wo_time_clock data;
 * no service swaps, no auto-refresh, no caching.
 *
 * Same role gate as the rest of the variance suite.
 */
final class ActiveNowController extends ControllerBase implements ContainerInjectionInterface {

  /** Status indicator thresholds in seconds. */
  private const GREEN_MAX  = 8 * 3600;       // < 8 hours
  private const YELLOW_MAX = 16 * 3600;      // 8-16 hours

  /**
   * Per-request memoization of resolved departments (keyed by uid).
   * Avoids re-querying teammate_profile.field_assigned_crew once per
   * row when many wo_time_clock entries share the same teammate.
   * Not persisted across requests — that would be over-engineering
   * at this scale.
   *
   * @var array<int, array{0: string, 1: int[]}>
   */
  private array $deptCache = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $em,
    private readonly DateFormatterInterface $dateFmt,
    private readonly FormBuilderInterface $forms,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('form_builder'),
    );
  }

  // ──────────────────────────────────────────────────────────────────────
  // MAIN BUILD
  // ──────────────────────────────────────────────────────────────────────

  public function build(Request $request): array {
    $deptFilter = (string) ($request->query->get('department') ?: 'all');
    $groupBy = (bool) $request->query->get('group_by_department');

    $build = [];
    $build['#attached']['library'][] = 'bos_teammate_operations/variance_dashboard';

    $build['header'] = $this->buildHeader();
    $build['filter_form'] = $this->forms->getForm(
      'Drupal\bos_teammate_operations\Form\ActiveNowFilterForm',
      ['department' => $deptFilter, 'group_by_department' => $groupBy],
    );
    $build['currently_clocked_in'] = $this->buildCurrentlyClockedInSection($deptFilter, $groupBy);
    $build['today_activity'] = $this->buildTodayActivitySection($deptFilter, $groupBy);

    return $build;
  }

  // ──────────────────────────────────────────────────────────────────────
  // HEADER
  // ──────────────────────────────────────────────────────────────────────

  protected function buildHeader(): array {
    $hubUrl = Url::fromRoute('bos_teammate_operations.hub')->toString();
    $now = time();
    $html = '<div class="bos-hub-header bos-active-now-header">'
      . '<p><a href="' . htmlspecialchars($hubUrl) . '">← Back to Teammate Operations Hub</a></p>'
      . '<h1>Active Now</h1>'
      . '<p class="bos-hub-subtitle">Real-time view of teammates currently clocked into work orders, with today\'s activity summary.</p>'
      . '<p class="bos-hub-timestamp">As of ' . htmlspecialchars($this->formatDateTimeUs($now)) . '</p>'
      . '</div>';
    return ['#markup' => $html, '#allowed_tags' => ['div', 'h1', 'p', 'a']];
  }

  // ──────────────────────────────────────────────────────────────────────
  // SECTION 1 — CURRENTLY CLOCKED IN
  // ──────────────────────────────────────────────────────────────────────

  protected function buildCurrentlyClockedInSection(string $deptFilter, bool $groupBy): array {
    $rows = $this->getCurrentlyClockedInRows($deptFilter);

    $html = '<h2 class="bos-active-section-heading">Currently Clocked In</h2>';
    if (empty($rows)) {
      $html .= '<p class="bos-active-empty">✓ No teammates currently clocked into a WO.</p>';
      return ['#markup' => $html, '#allowed_tags' => ['h2', 'p']];
    }

    // Sort by clock-in time DESC (most recently clocked in first).
    usort($rows, fn($a, $b) => strcmp($b['start_iso'], $a['start_iso']));

    $headers = ['Teammate', 'Work Order', 'Clocked In At', 'Duration', 'Status'];
    if ($groupBy) {
      $html .= $this->renderGroupedTable($rows, $headers, fn($row) => $this->renderClockedInRow($row));
    }
    else {
      $html .= $this->renderFlatTable($rows, $headers, fn($row) => $this->renderClockedInRow($row));
    }

    return [
      '#markup' => $html,
      '#allowed_tags' => ['h2', 'h3', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'a', 'span', 'p', 'div'],
    ];
  }

  /**
   * Find every open punch and assemble a row data array.
   *
   * @return array<int, array<string, mixed>>
   *   One entry per open wo_time_clock row.
   */
  protected function getCurrentlyClockedInRows(string $deptFilter): array {
    try {
      $ids = $this->em->getStorage('wo_time_clock')->getQuery()
        ->accessCheck(FALSE)
        ->exists('field_start_time')
        ->notExists('field_end_time')
        ->execute();
    }
    catch (\Throwable $e) {
      return [];
    }
    if (empty($ids)) return [];

    $entries = $this->em->getStorage('wo_time_clock')->loadMultiple($ids);
    $now = time();
    $deptId = ($deptFilter !== 'all' && $deptFilter !== '' && ctype_digit($deptFilter)) ? (int) $deptFilter : 0;
    $rows = [];
    foreach ($entries as $entry) {
      $teammate = $this->resolveTeammate($entry);
      if (!$teammate) continue;
      [$deptLabel, $deptIds] = $this->resolveDepartment($teammate);
      if ($deptId > 0 && !in_array($deptId, $deptIds, TRUE)) {
        continue;
      }

      $startIso = (string) ($entry->get('field_start_time')->value ?? '');
      $startTs = $startIso ? strtotime($startIso . 'Z') : 0;
      $duration = $startTs > 0 ? max(0, $now - $startTs) : 0;

      $woInfo = $this->resolveWorkOrder($entry);

      $rows[] = [
        'uid' => (int) $teammate->id(),
        'teammate_label' => $teammate->getDisplayName(),
        'dept_label' => $deptLabel,
        'wo_html' => $woInfo['html'],
        'start_iso' => $startIso,
        'start_ts' => $startTs,
        'duration_seconds' => $duration,
      ];
    }
    return $rows;
  }

  protected function renderClockedInRow(array $row): string {
    $detailUrl = Url::fromRoute('bos_teammate_operations.variance_teammate_detail', ['user' => $row['uid']])->toString();
    $teammateLink = '<a href="' . htmlspecialchars($detailUrl) . '">' . htmlspecialchars($row['teammate_label']) . '</a>';
    $clockedAt = $row['start_ts'] > 0 ? $this->formatDateTimeUs($row['start_ts']) : '—';
    $duration = $row['duration_seconds'] > 0 ? $this->formatDuration($row['duration_seconds']) : '—';
    $status = $this->getStatusIndicator($row['duration_seconds']);
    $statusDot = '<span class="bos-status-dot bos-status-' . $status . '" aria-label="' . htmlspecialchars($status) . '"></span>';
    return '<tr>'
      . '<td>' . $teammateLink . '</td>'
      . '<td>' . $row['wo_html'] . '</td>'
      . '<td>' . htmlspecialchars($clockedAt) . '</td>'
      . '<td>' . $statusDot . htmlspecialchars($duration) . '</td>'
      . '<td class="bos-status-cell">' . $statusDot . '</td>'
      . '</tr>';
  }

  // ──────────────────────────────────────────────────────────────────────
  // SECTION 2 — TODAY'S ACTIVITY
  // ──────────────────────────────────────────────────────────────────────

  protected function buildTodayActivitySection(string $deptFilter, bool $groupBy): array {
    $rows = $this->getTodayActivityRows($deptFilter);

    $html = '<h2 class="bos-active-section-heading">Today\'s Activity</h2>';
    if (empty($rows)) {
      $html .= '<p class="bos-active-empty">No teammate activity recorded today yet.</p>';
      return ['#markup' => $html, '#allowed_tags' => ['h2', 'p']];
    }

    // Sort by last_activity DESC.
    usort($rows, fn($a, $b) => $b['last_activity_ts'] <=> $a['last_activity_ts']);

    $headers = ['Teammate', 'WOs Touched Today', 'Total Closed Hrs', 'Currently On', 'Last Activity'];
    if ($groupBy) {
      $html .= $this->renderGroupedTable($rows, $headers, fn($row) => $this->renderTodayActivityRow($row));
    }
    else {
      $html .= $this->renderFlatTable($rows, $headers, fn($row) => $this->renderTodayActivityRow($row));
    }

    return [
      '#markup' => $html,
      '#allowed_tags' => ['h2', 'h3', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'a', 'span', 'p', 'div'],
    ];
  }

  /**
   * One row per teammate with any activity today (open or closed).
   *
   * @return array<int, array<string, mixed>>
   */
  protected function getTodayActivityRows(string $deptFilter): array {
    [$startUtc, $endUtc] = $this->todayRangeUtc();

    try {
      // Closed entries that started today.
      $closedIds = $this->em->getStorage('wo_time_clock')->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_start_time', $startUtc, '>=')
        ->condition('field_start_time', $endUtc, '<=')
        ->exists('field_end_time')
        ->execute();
      // Open entries (no end_time) — we include them even if start_time
      // is from yesterday because they represent "currently working today".
      $openIds = $this->em->getStorage('wo_time_clock')->getQuery()
        ->accessCheck(FALSE)
        ->exists('field_start_time')
        ->notExists('field_end_time')
        ->execute();
    }
    catch (\Throwable $e) {
      return [];
    }

    $allIds = array_unique(array_merge($closedIds, $openIds));
    if (empty($allIds)) return [];

    $entries = $this->em->getStorage('wo_time_clock')->loadMultiple($allIds);

    // Aggregate per teammate.
    $deptId = ($deptFilter !== 'all' && $deptFilter !== '' && ctype_digit($deptFilter)) ? (int) $deptFilter : 0;
    $perUser = [];
    foreach ($entries as $entry) {
      $teammate = $this->resolveTeammate($entry);
      if (!$teammate) continue;
      $uid = (int) $teammate->id();

      if (!isset($perUser[$uid])) {
        [$deptLabel, $deptIds] = $this->resolveDepartment($teammate);
        if ($deptId > 0 && !in_array($deptId, $deptIds, TRUE)) {
          $perUser[$uid] = NULL;
          continue;
        }
        $perUser[$uid] = [
          'uid' => $uid,
          'teammate_label' => $teammate->getDisplayName(),
          'dept_label' => $deptLabel,
          'wo_ids' => [],
          'closed_hours' => 0.0,
          'currently_on_html' => '—',
          'last_activity_ts' => 0,
        ];
      }
      if ($perUser[$uid] === NULL) continue;

      $startIso = (string) ($entry->get('field_start_time')->value ?? '');
      $endIso = (string) ($entry->get('field_end_time')->value ?? '');
      $startTs = $startIso ? strtotime($startIso . 'Z') : 0;
      $endTs = $endIso ? strtotime($endIso . 'Z') : 0;
      $isOpen = $endIso === '';
      $startsToday = $startIso !== '' && $startIso >= $startUtc && $startIso <= $endUtc;

      // Track WOs the teammate touched TODAY (closed today, or currently open).
      $woId = $entry->hasField('field_work_order') && !$entry->get('field_work_order')->isEmpty()
        ? (int) $entry->get('field_work_order')->target_id
        : 0;
      if ($woId > 0 && ($isOpen || $startsToday)) {
        $perUser[$uid]['wo_ids'][$woId] = TRUE;
      }

      // Sum closed hours from entries that STARTED today.
      if (!$isOpen && $startsToday) {
        $totalTime = $entry->hasField('field_total_time') && !$entry->get('field_total_time')->isEmpty()
          ? (float) $entry->get('field_total_time')->value
          : 0.0;
        $perUser[$uid]['closed_hours'] += $totalTime;
      }

      // Currently-on info from the open entry (if any).
      if ($isOpen) {
        $woInfo = $this->resolveWorkOrder($entry);
        $perUser[$uid]['currently_on_html'] = $woInfo['html'];
      }

      // Last activity = max of today-relevant timestamps for this teammate.
      // Only count timestamps that fall within today.
      $candidates = [];
      if ($startsToday && $startTs > 0) $candidates[] = $startTs;
      if ($endTs > 0 && $endIso >= $startUtc && $endIso <= $endUtc) $candidates[] = $endTs;
      foreach ($candidates as $ts) {
        if ($ts > $perUser[$uid]['last_activity_ts']) {
          $perUser[$uid]['last_activity_ts'] = $ts;
        }
      }
    }

    return array_values(array_filter($perUser));
  }

  protected function renderTodayActivityRow(array $row): string {
    $detailUrl = Url::fromRoute('bos_teammate_operations.variance_teammate_detail', ['user' => $row['uid']])->toString();
    $teammateLink = '<a href="' . htmlspecialchars($detailUrl) . '">' . htmlspecialchars($row['teammate_label']) . '</a>';
    $woCount = count($row['wo_ids']);
    $closedHrs = number_format($row['closed_hours'], 2);
    $lastTime = $row['last_activity_ts'] > 0 ? $this->formatTimeUs($row['last_activity_ts']) : '—';
    return '<tr>'
      . '<td>' . $teammateLink . '</td>'
      . '<td class="bos-numeric">' . $woCount . '</td>'
      . '<td class="bos-numeric">' . $closedHrs . '</td>'
      . '<td class="bos-currently-on-cell">' . $row['currently_on_html'] . '</td>'
      . '<td>' . htmlspecialchars($lastTime) . '</td>'
      . '</tr>';
  }

  // ──────────────────────────────────────────────────────────────────────
  // TABLE RENDERERS
  // ──────────────────────────────────────────────────────────────────────

  /** Render a flat sortable table from rows + headers + per-row callable. */
  protected function renderFlatTable(array $rows, array $headers, callable $rowRenderer): string {
    $html = '<table class="bos-active-now-table">';
    $html .= '<thead><tr>';
    foreach ($headers as $h) {
      $html .= '<th>' . htmlspecialchars($h) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
      $html .= $rowRenderer($row);
    }
    $html .= '</tbody></table>';
    return $html;
  }

  /** Render rows grouped by department with subheadings. */
  protected function renderGroupedTable(array $rows, array $headers, callable $rowRenderer): string {
    // Bucket rows by department label, preserving "—" for unassigned.
    $groups = [];
    foreach ($rows as $row) {
      $groups[$row['dept_label']][] = $row;
    }
    ksort($groups);

    $html = '';
    foreach ($groups as $deptLabel => $deptRows) {
      $count = count($deptRows);
      $html .= '<h3 class="bos-active-dept-heading">' . htmlspecialchars($deptLabel)
        . ' <span class="bos-active-dept-count">(' . $count . ')</span></h3>';
      $html .= $this->renderFlatTable($deptRows, $headers, $rowRenderer);
    }
    return $html;
  }

  // ──────────────────────────────────────────────────────────────────────
  // RESOLUTION HELPERS
  // ──────────────────────────────────────────────────────────────────────

  /**
   * Resolve the teammate for a wo_time_clock entry. Prefers field_teammate
   * (the explicit roster reference), falls back to the entry owner.
   * Returns NULL when neither resolves to a real user.
   */
  protected function resolveTeammate(EntityInterface $entry): ?UserInterface {
    if ($entry->hasField('field_teammate') && !$entry->get('field_teammate')->isEmpty()) {
      $u = $entry->get('field_teammate')->entity;
      if ($u instanceof UserInterface) return $u;
    }
    $ownerId = (int) ($entry->getOwnerId() ?? 0);
    if ($ownerId > 0) {
      $u = $this->em->getStorage('user')->load($ownerId);
      if ($u instanceof UserInterface) return $u;
    }
    return NULL;
  }

  /**
   * Department label + ids for a teammate. Returns ['label', [ids...]].
   * "—" label when no teammate_profile or no field_assigned_crew.
   */
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

  /** Build the linked WO id+title cell HTML. */
  protected function resolveWorkOrder(EntityInterface $entry): array {
    if (!$entry->hasField('field_work_order') || $entry->get('field_work_order')->isEmpty()) {
      return ['html' => '—', 'id' => 0];
    }
    $wo = $entry->get('field_work_order')->entity;
    if (!$wo) return ['html' => '—', 'id' => 0];
    $woId = (int) $wo->id();
    $woTitle = $wo->label() ?: ('WO #' . $woId);
    $woUrl = Url::fromUri('internal:/work_order/' . $woId)->toString();
    $html = '<a href="' . htmlspecialchars($woUrl) . '">' . $woId . '</a> '
      . '<span class="bos-wo-title">' . htmlspecialchars((string) $woTitle) . '</span>';
    return ['html' => $html, 'id' => $woId];
  }

  // ──────────────────────────────────────────────────────────────────────
  // FORMATTERS
  // ──────────────────────────────────────────────────────────────────────

  /** "X hr Y min" — omits the hr part when under an hour. */
  protected function formatDuration(int $seconds): string {
    $hrs = intdiv($seconds, 3600);
    $mins = intdiv($seconds % 3600, 60);
    if ($hrs === 0) {
      return $mins . ' min';
    }
    return $hrs . ' hr ' . $mins . ' min';
  }

  /** Status: green (<8h) | yellow (8-16h) | red (>16h) | na (no duration). */
  protected function getStatusIndicator(int $durationSeconds): string {
    if ($durationSeconds <= 0) return 'na';
    if ($durationSeconds < self::GREEN_MAX) return 'green';
    if ($durationSeconds <= self::YELLOW_MAX) return 'yellow';
    return 'red';
  }

  /** MM/DD/YYYY h:i AM/PM in site default timezone. */
  protected function formatDateTimeUs(int $timestamp): string {
    return $this->dateFmt->format($timestamp, 'custom', 'm/d/Y g:i A');
  }

  /** h:i AM/PM only — for "today" context where date is implied. */
  protected function formatTimeUs(int $timestamp): string {
    return $this->dateFmt->format($timestamp, 'custom', 'g:i A');
  }

  /** [start_utc_iso, end_utc_iso] for today in the site's timezone. */
  protected function todayRangeUtc(): array {
    $localTz = new \DateTimeZone(date_default_timezone_get());
    $utc = new \DateTimeZone('UTC');
    $start = (new \DateTime('today 00:00:00', $localTz))->setTimezone($utc);
    $end = (new \DateTime('today 23:59:59', $localTz))->setTimezone($utc);
    return [$start->format('Y-m-d\TH:i:s'), $end->format('Y-m-d\TH:i:s')];
  }

}
