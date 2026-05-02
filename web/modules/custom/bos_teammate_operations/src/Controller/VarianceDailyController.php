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
 * Daily Variance dashboard + Time Clock data hygiene check page.
 *
 * Drives one of the two routes defined in
 * bos_teammate_operations.routing.yml. Both render server-side via
 * the CompensableHoursService — no caching at this scale (~25
 * teammates × 30 days). If page loads exceed ~3s in real use,
 * adding render caching is a follow-up.
 */
final class VarianceDailyController extends ControllerBase implements ContainerInjectionInterface {

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

  // ──────────────────────────────────────────────────────────────────────
  // MAIN VARIANCE PAGE
  // ──────────────────────────────────────────────────────────────────────

  public function build(Request $request): array {
    // ── filters ────────────────────────────────────────────────────────
    $boundary = $this->compensableHours->getDataQualityBoundary();
    $boundaryStr = $boundary->format('Y-m-d');

    $startQuery = $request->query->get('start_date');
    if ($startQuery) {
      $start = $startQuery;
    }
    else {
      // Default start: max(today - 30 days, boundary).
      $candidate = date('Y-m-d', strtotime('-30 days'));
      $start = ($candidate < $boundaryStr) ? $boundaryStr : $candidate;
    }
    $end   = $request->query->get('end_date')   ?: date('Y-m-d');
    $deptFilter = $request->query->get('department') ?: 'all';
    $teammateFilter = (int) ($request->query->get('teammate') ?: 0);
    $showInactive = (bool) $request->query->get('show_inactive');

    $preBoundary = ($start < $boundaryStr);

    // ── filter form ────────────────────────────────────────────────────
    $form = $this->forms->getForm(
      'Drupal\bos_teammate_operations\Form\VarianceDailyFilterForm',
      [
        'start_date'    => $start,
        'end_date'      => $end,
        'department'    => $deptFilter,
        'teammate'      => $teammateFilter ?: NULL,
        'show_inactive' => $showInactive,
        'boundary_date' => $boundaryStr,
      ]
    );

    // ── teammate set ───────────────────────────────────────────────────
    $teammates = $this->getTeammates($deptFilter, $teammateFilter, $showInactive, $start, $end);

    // ── per-row aggregation ────────────────────────────────────────────
    $thresholds = $this->compensableHours->getVarianceThresholds();
    $rows = [];
    foreach ($teammates as $user) {
      $data = $this->calculateRowData($user, $start, $end);
      // Skip rows with zero activity unless show_inactive is on.
      if (!$showInactive && $data['days_active'] === 0) {
        continue;
      }
      $rows[] = $this->renderRow($user, $data, $thresholds, $start, $end);
    }

    // ── default sort: productive % ASC (lowest = top of list) ───────────
    $sortField = $request->query->get('order') ?: 'Productive %';
    $sortDir   = strtolower($request->query->get('sort') ?: 'asc');
    usort($rows, fn($a, $b) => $this->rowSortCmp($a, $b, $sortField, $sortDir));

    // ── render array ───────────────────────────────────────────────────
    $build = [];
    $build['#attached']['library'][] = 'bos_teammate_operations/variance_dashboard';

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

    $build['summary'] = [
      '#markup' => '<div class="bos-variance-summary">'
        . $this->t('Showing variance for <strong>@n</strong> teammates from <strong>@s</strong> to <strong>@e</strong>.', [
          '@n' => count($rows),
          '@s' => $start,
          '@e' => $end,
        ])->render()
        . ' ' . $this->t('<a href=":url">Time Clock data hygiene check ↗</a>', [
          ':url' => Url::fromRoute('bos_teammate_operations.variance_data_check')->toString(),
        ])->render()
        . '</div>',
      '#allowed_tags' => ['div', 'strong', 'a'],
    ];

    if (empty($rows)) {
      $build['empty'] = [
        '#markup' => '<div class="bos-variance-empty">'
          . $this->t('No teammates match the current filters.')->render()
          . '</div>',
      ];
      return $build;
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $this->buildHeader($sortField, $sortDir),
      '#rows' => $rows,
      '#attributes' => ['class' => ['bos-variance-table']],
    ];

    return $build;
  }

  // ──────────────────────────────────────────────────────────────────────
  // HELPERS — main view
  // ──────────────────────────────────────────────────────────────────────

  /**
   * @return UserInterface[]
   */
  protected function getTeammates(
    string $deptFilter,
    int $teammateFilter,
    bool $showInactive,
    string $startDate,
    string $endDate,
  ): array {
    if ($teammateFilter > 0) {
      $user = $this->em->getStorage('user')->load($teammateFilter);
      return $user instanceof UserInterface ? [$user] : [];
    }

    $query = $this->em->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', 'teammates')
      ->sort('name', 'ASC');

    $uids = $query->execute();
    if (empty($uids)) {
      return [];
    }
    $users = $this->em->getStorage('user')->loadMultiple($uids);

    // Department filter — runs against teammate_profile.field_assigned_crew.
    if ($deptFilter !== 'all' && $deptFilter !== '' && $deptFilter !== NULL) {
      $deptId = (int) $deptFilter;
      $users = array_filter($users, function (UserInterface $u) use ($deptId): bool {
        return $this->userHasCrew($u, $deptId);
      });
    }

    return $users;
  }

  protected function userHasCrew(UserInterface $user, int $crewId): bool {
    $profile_storage = $this->em->getStorage('profile');
    $profiles = $profile_storage->loadByProperties([
      'uid' => $user->id(),
      'type' => 'teammate_profile',
    ]);
    foreach ($profiles as $profile) {
      if (!$profile->hasField('field_assigned_crew') || $profile->get('field_assigned_crew')->isEmpty()) {
        continue;
      }
      foreach ($profile->get('field_assigned_crew') as $item) {
        if ((int) $item->target_id === $crewId) {
          return TRUE;
        }
      }
    }
    return FALSE;
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

  protected function calculateRowData(UserInterface $user, string $startDate, string $endDate): array {
    $uid = (int) $user->id();
    $totalComp = 0.0;
    $totalWo   = 0.0;
    $totalVar  = 0.0;
    $daysActive = 0;

    foreach ($this->datesBetween($startDate, $endDate) as $date) {
      $hadActivity = $this->compensableHours->hasWoActivityOnDate($uid, $date);
      if (!$hadActivity) {
        continue;
      }
      $daysActive++;
      $comp = $this->compensableHours->getCompensableHoursForUserOnDate($uid, $date);
      $wo   = $this->compensableHours->getWoHoursForUserOnDate($uid, $date);
      $totalComp += $comp;
      $totalWo   += $wo;
      $totalVar  += ($comp - $wo);
    }

    $avgVar = $daysActive > 0 ? round($totalVar / $daysActive, 2) : 0.0;
    $productPct = $totalComp > 0 ? round(($totalWo / $totalComp) * 100.0, 1) : NULL;

    return [
      'days_active'     => $daysActive,
      'total_comp'      => round($totalComp, 2),
      'total_wo'        => round($totalWo, 2),
      'total_variance'  => round($totalVar, 2),
      'avg_variance'    => $avgVar,
      'productive_pct'  => $productPct,
    ];
  }

  /**
   * Build a single rendered table row from raw row data.
   */
  protected function renderRow(UserInterface $user, array $data, array $thresholds, string $start = '', string $end = ''): array {
    $hadActivity = $data['days_active'] > 0;
    $statusForAvg = $this->compensableHours->getVarianceStatus($data['avg_variance'], $hadActivity);
    $varClass = 'bos-variance-' . $statusForAvg;

    // Productivity color: high % is green, low % is red — invert the variance scale.
    $pctClass = 'bos-variance-na';
    if ($data['productive_pct'] !== NULL) {
      // We translate productivity % into a pseudo-variance using the same
      // thresholds: how far is the productive_pct from 100%? Fewer hours
      // accounted-for by WO = larger gap = worse status.
      $gap_hours = ($data['total_comp'] > 0)
        ? ($data['total_comp'] - $data['total_wo']) / max(1, $data['days_active'])
        : 0.0;
      $pctStatus = $this->compensableHours->getVarianceStatus($gap_hours, TRUE);
      $pctClass = 'bos-variance-' . $pctStatus;
    }

    // Link teammate name to the per-teammate detail page (Phase 2C),
    // carrying forward the rollup's date range so the detail page
    // inherits the same window without forcing a re-select.
    $detailUrl = Url::fromRoute(
      'bos_teammate_operations.variance_teammate_detail',
      ['user' => $user->id()],
      ['query' => array_filter(['start_date' => $start, 'end_date' => $end])]
    );
    $teammateLink = '<a href="' . htmlspecialchars($detailUrl->toString()) . '">'
      . htmlspecialchars($user->getDisplayName()) . '</a>';

    return [
      'data' => [
        ['data' => ['#markup' => $teammateLink]],
        ['data' => $this->userDepartmentLabel($user)],
        ['data' => $hadActivity ? $data['days_active'] : '—', 'class' => ['bos-numeric']],
        ['data' => $hadActivity ? $this->formatHours($data['total_comp']) : '—', 'class' => ['bos-numeric']],
        ['data' => $hadActivity ? $this->formatHours($data['total_wo']) : '—', 'class' => ['bos-numeric']],
        ['data' => $hadActivity ? $this->formatHours($data['total_variance']) : '—', 'class' => ['bos-numeric']],
        [
          'data' => $hadActivity ? $this->formatHours($data['avg_variance']) : '—',
          'class' => ['bos-numeric', $varClass],
        ],
        [
          'data' => $this->formatPercent($data['productive_pct']),
          'class' => ['bos-numeric', $pctClass],
        ],
      ],
      // Stash sort keys for usort — these are NOT rendered.
      '_sort' => [
        'Teammate'        => mb_strtolower($user->getDisplayName()),
        'Department'      => mb_strtolower($this->userDepartmentLabel($user)),
        'Days Active'     => (int) $data['days_active'],
        'Compensable hrs' => (float) $data['total_comp'],
        'WO hrs'          => (float) $data['total_wo'],
        'Total Variance'  => (float) $data['total_variance'],
        'Avg Daily Var'   => (float) $data['avg_variance'],
        'Productive %'    => $data['productive_pct'] === NULL ? -1.0 : (float) $data['productive_pct'],
      ],
    ];
  }

  /**
   * usort comparator that uses the _sort field stashed by renderRow().
   */
  protected function rowSortCmp(array $a, array $b, string $field, string $dir): int {
    $av = $a['_sort'][$field] ?? 0;
    $bv = $b['_sort'][$field] ?? 0;
    if (is_string($av)) {
      $cmp = strcmp($av, $bv);
    }
    else {
      $cmp = $av <=> $bv;
    }
    return $dir === 'desc' ? -$cmp : $cmp;
  }

  /**
   * Build sortable table header. Each header carries a link that toggles
   * the sort field/direction by setting query params.
   */
  protected function buildHeader(string $sortField, string $sortDir): array {
    $headers = [
      'Teammate', 'Department', 'Days Active',
      'Compensable hrs', 'WO hrs', 'Total Variance',
      'Avg Daily Var', 'Productive %',
    ];

    $built = [];
    foreach ($headers as $name) {
      $isActive = $sortField === $name;
      $newDir = $isActive ? ($sortDir === 'asc' ? 'desc' : 'asc') : 'asc';
      $arrow = $isActive ? ($sortDir === 'asc' ? ' ↑' : ' ↓') : '';
      $url = Url::fromRoute('<current>', [], [
        'query' => array_merge(\Drupal::request()->query->all(), [
          'order' => $name,
          'sort' => $newDir,
        ]),
      ])->toString();
      $built[] = ['data' => ['#markup' => '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($name) . $arrow . '</a>']];
    }
    return $built;
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

  protected function formatHours(?float $hours): string {
    if ($hours === NULL) {
      return '—';
    }
    return number_format((float) $hours, 2);
  }

  protected function formatPercent(?float $pct): string {
    if ($pct === NULL) {
      return '—';
    }
    return number_format((float) $pct, 1) . '%';
  }

  // ──────────────────────────────────────────────────────────────────────
  // DATA HYGIENE CHECK
  // ──────────────────────────────────────────────────────────────────────

  public function dataCheck(Request $request): array {
    $build = [];
    $build['#attached']['library'][] = 'bos_teammate_operations/variance_dashboard';
    $build['#attributes']['class'][] = 'bos-variance-data-check';

    $boundary = $this->compensableHours->getDataQualityBoundary();
    $boundaryStr = $boundary->format('Y-m-d');
    $showAll = (bool) $request->query->get('show_all');

    // Pre-compute the full row sets (we need them either way for the
    // active vs historical counts at the top). Anomaly type → label
    // mapping comes from the AnomalyDetectionService so it stays in
    // sync with per-row detection on the teammate detail page.
    $typeLabels = [
      'negative_hours'   => 'Negative total_time',
      'implausible_long' => 'Implausibly long shifts (> 16 hrs)',
      'future_start'     => 'Future start_time',
      'open_stale'       => 'Forgotten clock-outs (> 7 days open)',
      'time_travel'      => 'End time before start time',
    ];
    $allChecks = [];
    foreach ($typeLabels as $type => $label) {
      $entries = $this->anomalyDetection->findAnomaliesByType($type);
      $allChecks[$label] = $this->renderRowsForEntries($entries);
    }
    $activeTotal = 0;
    $historicalTotal = 0;
    foreach ($allChecks as $rows) {
      foreach ($rows as $r) {
        if ($this->rowIsPostBoundary($r, $boundaryStr)) {
          $activeTotal++;
        }
        else {
          $historicalTotal++;
        }
      }
    }

    // Top summary block + toggle.
    $toggleUrl = Url::fromRoute('bos_teammate_operations.variance_data_check', [], [
      'query' => $showAll ? [] : ['show_all' => 1],
    ])->toString();
    $toggleLabel = $showAll
      ? $this->t('← Show only active anomalies')
      : $this->t('Show all anomalies including pre-boundary data →');

    $build['intro'] = [
      '#markup' => '<p>'
        . $this->t('Diagnostic report of suspicious <code>wo_time_clock</code> records. Rows are not modified or deleted by this page — review and clean up manually as appropriate.')->render()
        . '</p>'
        . '<div class="bos-variance-data-check-counts">'
        . '<span class="count-active">'
        . $this->t('<strong>Active anomalies</strong> (since @b): @n', ['@b' => $boundaryStr, '@n' => $activeTotal])->render()
        . '</span> · '
        . '<span class="count-historical">'
        . $this->t('<strong>Historical anomalies</strong> (before @b): @m', ['@b' => $boundaryStr, '@m' => $historicalTotal])->render()
        . '</span> · '
        . '<a href="' . htmlspecialchars($toggleUrl) . '">' . $toggleLabel . '</a>'
        . '</div>',
      '#allowed_tags' => ['p', 'code', 'div', 'span', 'strong', 'a'],
    ];

    foreach ($allChecks as $title => $rows) {
      $rows = $showAll
        ? $rows
        : array_values(array_filter($rows, fn(array $r) => $this->rowIsPostBoundary($r, $boundaryStr)));
      $count = count($rows);
      $pillClass = $count === 0 ? 'zero' : 'nonzero';
      $build['heading_' . md5($title)] = [
        '#markup' => '<h2>' . htmlspecialchars($title)
          . '<span class="summary-pill ' . $pillClass . '">' . $count . ' rows</span>'
          . '</h2>',
      ];
      if ($count === 0) {
        $build['empty_' . md5($title)] = [
          '#markup' => '<p><em>No matching rows.</em></p>',
        ];
        continue;
      }
      $build['table_' . md5($title)] = [
        '#type' => 'table',
        '#header' => ['ID', 'Teammate', 'Start Time', 'End Time', 'Total Time', 'Notes'],
        '#rows' => $rows,
        '#attributes' => ['class' => ['bos-variance-table']],
      ];
    }

    return $build;
  }

  /**
   * The render rows from check methods carry "Start Time" in column 2
   * (index 2). Compare its date prefix against the boundary string.
   */
  protected function rowIsPostBoundary(array $row, string $boundaryStr): bool {
    $start = (string) ($row['data'][2]['data'] ?? '');
    if ($start === '' || $start === '—') {
      // Forgotten clock-outs without a sensible start_time are surfaced
      // as "active" — they need attention regardless of when they began.
      return TRUE;
    }
    return substr($start, 0, 10) >= $boundaryStr;
  }

  /**
   * Build human-readable rows for an array of wo_time_clock entities.
   *
   * @param EntityInterface[] $entries
   *
   * @return array
   */
  protected function renderRowsForEntries(array $entries): array {
    if (empty($entries)) {
      return [];
    }
    $rows = [];
    foreach ($entries as $entry) {
      $teammateLabel = '—';
      if ($entry->hasField('field_teammate') && !$entry->get('field_teammate')->isEmpty()) {
        $u = $entry->get('field_teammate')->entity;
        if ($u) {
          $teammateLabel = $u->getDisplayName() . ' (uid ' . $u->id() . ')';
        }
      }
      $start = $entry->hasField('field_start_time') && !$entry->get('field_start_time')->isEmpty()
        ? $entry->get('field_start_time')->value : '—';
      $end = $entry->hasField('field_end_time') && !$entry->get('field_end_time')->isEmpty()
        ? $entry->get('field_end_time')->value : '(open)';
      $total = $entry->hasField('field_total_time') && !$entry->get('field_total_time')->isEmpty()
        ? (string) $entry->get('field_total_time')->value : '—';
      $notes = $entry->hasField('field_notes') && !$entry->get('field_notes')->isEmpty()
        ? mb_substr((string) $entry->get('field_notes')->value, 0, 60) : '';
      $idLink = Url::fromUri('internal:/wo_time_clock/' . $entry->id())->toString();
      $rows[] = [
        'data' => [
          ['data' => ['#markup' => '<a href="' . htmlspecialchars($idLink) . '">' . (int) $entry->id() . '</a>']],
          ['data' => $teammateLabel],
          ['data' => $start],
          ['data' => $end],
          ['data' => $total, 'class' => ['bos-numeric']],
          ['data' => $notes],
        ],
      ];
    }
    return $rows;
  }

}
