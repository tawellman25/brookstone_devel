<?php

declare(strict_types=1);

namespace Drupal\bos_teammate_operations\Controller;

use Drupal\bos_teammate_operations\Service\CompensableHoursService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
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
    private readonly EntityTypeManagerInterface $em,
    private readonly FormBuilderInterface $forms,
    private readonly DateFormatterInterface $dateFmt,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('bos_teammate_operations.compensable_hours'),
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
    $start = $request->query->get('start_date') ?: date('Y-m-d', strtotime('-30 days'));
    $end   = $request->query->get('end_date')   ?: date('Y-m-d');
    $deptFilter = $request->query->get('department') ?: 'all';
    $teammateFilter = (int) ($request->query->get('teammate') ?: 0);
    $showInactive = (bool) $request->query->get('show_inactive');

    // ── filter form ────────────────────────────────────────────────────
    $form = $this->forms->getForm(
      'Drupal\bos_teammate_operations\Form\VarianceDailyFilterForm',
      [
        'start_date'    => $start,
        'end_date'      => $end,
        'department'    => $deptFilter,
        'teammate'      => $teammateFilter ?: NULL,
        'show_inactive' => $showInactive,
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
      $rows[] = $this->renderRow($user, $data, $thresholds);
    }

    // ── default sort: productive % ASC (lowest = top of list) ───────────
    $sortField = $request->query->get('order') ?: 'Productive %';
    $sortDir   = strtolower($request->query->get('sort') ?: 'asc');
    usort($rows, fn($a, $b) => $this->rowSortCmp($a, $b, $sortField, $sortDir));

    // ── render array ───────────────────────────────────────────────────
    $build = [];
    $build['#attached']['library'][] = 'bos_teammate_operations/variance_dashboard';

    $build['filters'] = $form;

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
  protected function renderRow(UserInterface $user, array $data, array $thresholds): array {
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

    $teammateLink = $user->toLink($user->getDisplayName(), 'edit-form')->toString();

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

  public function dataCheck(): array {
    $build = [];
    $build['#attached']['library'][] = 'bos_teammate_operations/variance_dashboard';
    $build['#attributes']['class'][] = 'bos-variance-data-check';

    $build['intro'] = [
      '#markup' => '<p>'
        . $this->t('Diagnostic report of suspicious <code>wo_time_clock</code> records. Rows are not modified or deleted by this page — review and clean up manually as appropriate.')->render()
        . '</p>',
    ];

    $checks = [
      'Negative total_time'     => $this->checkNegativeHours(),
      'Implausibly long shifts (> 16 hrs)' => $this->checkLongShifts(),
      'Future start_time'       => $this->checkFutureStart(),
      'Forgotten clock-outs (> 7 days open)' => $this->checkOpenStale(),
      'End time before start time' => $this->checkTimeTravel(),
    ];

    foreach ($checks as $title => $rows) {
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

  protected function checkNegativeHours(): array {
    $storage = $this->em->getStorage('wo_time_clock');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_total_time', 0, '<')
      ->range(0, 200)
      ->execute();
    return $this->renderRowsForIds($ids);
  }

  protected function checkLongShifts(): array {
    $storage = $this->em->getStorage('wo_time_clock');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_total_time', 16, '>')
      ->range(0, 200)
      ->execute();
    return $this->renderRowsForIds($ids);
  }

  protected function checkFutureStart(): array {
    $storage = $this->em->getStorage('wo_time_clock');
    $todayUtcEnd = (new \DateTime('tomorrow 00:00:00', new \DateTimeZone(date_default_timezone_get())))
      ->setTimezone(new \DateTimeZone('UTC'))
      ->format('Y-m-d\TH:i:s');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_start_time', $todayUtcEnd, '>')
      ->range(0, 200)
      ->execute();
    return $this->renderRowsForIds($ids);
  }

  protected function checkOpenStale(): array {
    $storage = $this->em->getStorage('wo_time_clock');
    $cutoff = (new \DateTime('-7 days', new \DateTimeZone(date_default_timezone_get())))
      ->setTimezone(new \DateTimeZone('UTC'))
      ->format('Y-m-d\TH:i:s');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->notExists('field_end_time')
      ->condition('field_start_time', $cutoff, '<')
      ->range(0, 200)
      ->execute();
    return $this->renderRowsForIds($ids);
  }

  protected function checkTimeTravel(): array {
    // Loadable rows where end < start. Entity query can't compare two
    // fields directly, so pull a window and filter in PHP.
    $storage = $this->em->getStorage('wo_time_clock');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->exists('field_start_time')
      ->exists('field_end_time')
      ->sort('field_start_time', 'DESC')
      ->range(0, 5000)
      ->execute();
    if (empty($ids)) {
      return [];
    }
    $entries = $storage->loadMultiple($ids);
    $bad = [];
    foreach ($entries as $entry) {
      $s = $entry->get('field_start_time')->value;
      $e = $entry->get('field_end_time')->value;
      if ($s && $e && strtotime($e) < strtotime($s)) {
        $bad[] = $entry->id();
      }
    }
    return $this->renderRowsForIds($bad);
  }

  /**
   * Build human-readable rows for a list of wo_time_clock entity IDs.
   *
   * @param int[]|string[] $ids
   *
   * @return array
   */
  protected function renderRowsForIds(array $ids): array {
    if (empty($ids)) {
      return [];
    }
    $entries = $this->em->getStorage('wo_time_clock')->loadMultiple($ids);
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
