<?php

declare(strict_types=1);

namespace Drupal\bos_daily_recap\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Daily Recap dashboard: per-department value cards for yesterday / WTD / MTD,
 * plus a completions list below that re-targets to whatever department + range
 * total you click up top (default: yesterday, all departments).
 *
 * Completion is anchored on wo_complete_info.field_date_completed (a timestamp).
 * Revenue is work_order.field_wo_total; department resolves via field_service ->
 * services term -> field_department -> department. Warrantied WOs (status 1283)
 * are excluded everywhere; a WO completed more than once is counted once.
 *
 * Mowing note: lawn mowing is contract-billed, so its field_wo_total is ~$0 —
 * the per-department $ under-reports mowing on purpose (the completions count
 * shows the volume). See __BOS_AI/Architecture/daily_recap_dashboard.md.
 */
final class DailyRecapController extends ControllerBase {

  private const STATUS_WARRANTIED = 1283;

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Dashboard. ?range= & ?dept= select which total the bottom list shows.
   */
  public function view(Request $request): array {
    $windows = $this->windows();
    $sel_range = (string) $request->query->get('range', '');
    $sel_dept = $request->query->get('dept', NULL);
    $has_selection = $sel_range !== '' && isset($windows[$sel_range]) && $sel_dept !== NULL && $sel_dept !== '';

    // Cards.
    $window_out = [];
    foreach ($windows as $key => $w) {
      $summary = $this->summarize($this->completedWorkOrderIds($w['start'], $w['end']));
      $total_value = 0.0;
      $total_count = 0;
      $rows = [];
      foreach ($summary as $dept_id => $d) {
        $total_value += $d['value'];
        $total_count += $d['count'];
        $rows[] = [
          'dept' => $d['label'],
          'value' => $this->money($d['value']),
          'count' => $d['count'],
          'url' => Url::fromRoute('bos_daily_recap.dashboard', [], ['query' => ['range' => $key, 'dept' => $dept_id]])->toString(),
          'active' => $has_selection && $sel_range === $key && (string) $sel_dept === (string) $dept_id,
        ];
      }
      $window_out[] = [
        'key' => $key,
        'label' => $w['label'],
        'rows' => $rows,
        'total_value' => $this->money($total_value),
        'total_count' => $total_count,
      ];
    }

    // Bottom list: the selected department+range, or yesterday/all by default.
    if ($has_selection) {
      $w = $windows[$sel_range];
      $list_rows = $this->completions($w['start'], $w['end'], (string) $sel_dept);
      $list_label = $this->departmentLabel((string) $sel_dept) . ' · ' . $w['label'];
    }
    else {
      $w = $windows['yesterday'];
      $list_rows = $this->completions($w['start'], $w['end']);
      $list_label = $windows['yesterday']['label'] . ' — all completions';
    }
    $list_total = 0.0;
    foreach ($list_rows as &$r) {
      $list_total += $r['value_raw'];
      unset($r['value_raw']);
    }
    unset($r);

    return [
      '#theme' => 'daily_recap',
      '#generated_at' => $this->formatDateTimeUs($this->time->getRequestTime()),
      '#windows' => $window_out,
      '#list_label' => $list_label,
      '#list_rows' => $list_rows,
      '#list_total' => $this->money($list_total),
      '#list_count' => count($list_rows),
      '#selection_active' => $has_selection,
      '#clear_url' => Url::fromRoute('bos_daily_recap.dashboard')->toString(),
      '#mowing_note' => 'Lawn mowing is contract-billed, so its dollar value is not carried on the work order — the per-department $ shows ~$0 for mowing; use the completions count for mowing volume.',
      '#attached' => ['library' => ['bos_daily_recap/dashboard']],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['url.query_args:range', 'url.query_args:dept'],
      ],
    ];
  }

  /* ---------- windows ---------- */

  private function windows(): array {
    $tz = new \DateTimeZone(date_default_timezone_get());
    $now = new \DateTime('@' . $this->time->getRequestTime());
    $now->setTimezone($tz);

    $y_start = (clone $now)->modify('yesterday')->setTime(0, 0, 0);
    $y_end = (clone $y_start)->setTime(23, 59, 59);

    // Week-to-date, Sunday start.
    $dow = (int) $now->format('w');
    $w_start = (clone $now)->modify("-{$dow} days")->setTime(0, 0, 0);

    $m_start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);

    return [
      'yesterday' => [
        'label' => 'Yesterday (' . $y_start->format('m/d/Y') . ')',
        'start' => $y_start->getTimestamp(),
        'end' => $y_end->getTimestamp(),
      ],
      'wtd' => [
        'label' => 'Week to date (since ' . $w_start->format('m/d/Y') . ')',
        'start' => $w_start->getTimestamp(),
        'end' => $now->getTimestamp(),
      ],
      'mtd' => [
        'label' => 'Month to date (since ' . $m_start->format('m/d/Y') . ')',
        'start' => $m_start->getTimestamp(),
        'end' => $now->getTimestamp(),
      ],
    ];
  }

  /* ---------- data ---------- */

  private function completedWorkOrderIds(int $start, int $end): array {
    $ci_ids = $this->etm->getStorage('wo_complete_info')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_date_completed', $start, '>=')
      ->condition('field_date_completed', $end, '<=')
      ->execute();
    $wo_ids = [];
    foreach (array_chunk($ci_ids, 300) as $chunk) {
      foreach ($this->etm->getStorage('wo_complete_info')->loadMultiple($chunk) as $ci) {
        $wid = ($ci->hasField('field_work_order') && !$ci->get('field_work_order')->isEmpty())
          ? $ci->get('field_work_order')->target_id : NULL;
        if ($wid) {
          $wo_ids[$wid] = (int) $wid;
        }
      }
    }
    return array_values($wo_ids);
  }

  private function summarize(array $wo_ids): array {
    $by_dept = [];
    foreach (array_chunk($wo_ids, 300) as $chunk) {
      foreach ($this->etm->getStorage('work_order')->loadMultiple($chunk) as $wo) {
        if ((int) ($wo->get('field_status')->target_id ?? 0) === self::STATUS_WARRANTIED) {
          continue;
        }
        [$dept_id, $dept_label] = $this->resolveDepartment($wo);
        if (!isset($by_dept[$dept_id])) {
          $by_dept[$dept_id] = ['label' => $dept_label, 'value' => 0.0, 'count' => 0];
        }
        $by_dept[$dept_id]['value'] += $this->woTotal($wo);
        $by_dept[$dept_id]['count']++;
      }
    }
    uasort($by_dept, fn($a, $b) => strcmp($a['label'], $b['label']));
    return $by_dept;
  }

  /**
   * Detailed completions for a window, deduped by WO (latest completion),
   * warranty-excluded, optionally filtered to one department id.
   */
  private function completions(int $start, int $end, ?string $dept_filter = NULL): array {
    $ci_ids = $this->etm->getStorage('wo_complete_info')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_date_completed', $start, '>=')
      ->condition('field_date_completed', $end, '<=')
      ->execute();
    $by_wo = [];
    foreach (array_chunk($ci_ids, 300) as $chunk) {
      foreach ($this->etm->getStorage('wo_complete_info')->loadMultiple($chunk) as $ci) {
        $wid = ($ci->hasField('field_work_order') && !$ci->get('field_work_order')->isEmpty())
          ? (int) $ci->get('field_work_order')->target_id : 0;
        if (!$wid) {
          continue;
        }
        $ts = (int) $ci->get('field_date_completed')->value;
        if (isset($by_wo[$wid]) && $by_wo[$wid]['_ts'] >= $ts) {
          continue;
        }
        $wo = $this->etm->getStorage('work_order')->load($wid);
        if (!$wo || (int) ($wo->get('field_status')->target_id ?? 0) === self::STATUS_WARRANTIED) {
          continue;
        }
        [$dept_id, $dept_label] = $this->resolveDepartment($wo);
        if ($dept_filter !== NULL && $dept_id !== $dept_filter) {
          continue;
        }
        $value = $this->woTotal($wo);
        $by_wo[$wid] = [
          '_ts' => $ts,
          'wo' => $this->woNumber($wo),
          'wo_url' => $wo->toUrl()->toString(),
          'service' => $this->serviceLabel($wo),
          'property' => $this->propertyLabel($wo),
          'nickname' => $this->propertyNickname($wo),
          'dept' => $dept_label,
          'value' => $this->money($value),
          'value_raw' => $value,
          'completed_at' => $this->formatDateTimeUs($ts),
        ];
      }
    }
    usort($by_wo, fn($a, $b) => $b['_ts'] <=> $a['_ts']);
    foreach ($by_wo as &$r) {
      unset($r['_ts']);
    }
    return array_values($by_wo);
  }

  /* ---------- resolvers ---------- */

  /**
   * @return array{0:string,1:string} [department id ('unassigned' if none), label]
   */
  private function resolveDepartment(EntityInterface $wo): array {
    if (!$wo->hasField('field_service') || $wo->get('field_service')->isEmpty()) {
      return ['unassigned', 'Unassigned'];
    }
    $term = $wo->get('field_service')->entity;
    if (!$term || !$term->hasField('field_department') || $term->get('field_department')->isEmpty()) {
      return ['unassigned', 'Unassigned'];
    }
    $dept = $term->get('field_department')->entity;
    if (!$dept) {
      return ['unassigned', 'Unassigned'];
    }
    return [(string) $dept->id(), $dept->label()];
  }

  private function departmentLabel(string $dept_id): string {
    if ($dept_id === 'unassigned') {
      return 'Unassigned';
    }
    $dept = $this->etm->getStorage('department')->load($dept_id);
    return $dept ? $dept->label() : 'Unassigned';
  }

  private function woTotal(EntityInterface $wo): float {
    return ($wo->hasField('field_wo_total') && !$wo->get('field_wo_total')->isEmpty())
      ? (float) $wo->get('field_wo_total')->value : 0.0;
  }

  private function woNumber(EntityInterface $wo): string {
    if ($wo->hasField('field_work_order_id') && !$wo->get('field_work_order_id')->isEmpty()) {
      return '#' . $wo->get('field_work_order_id')->value;
    }
    return '#' . $wo->id();
  }

  private function serviceLabel(EntityInterface $wo): string {
    if ($wo->hasField('field_service') && !$wo->get('field_service')->isEmpty()) {
      $term = $wo->get('field_service')->entity;
      if ($term) {
        return $term->label();
      }
    }
    return ucwords(str_replace('_', ' ', $wo->bundle()));
  }

  private function propertyLabel(EntityInterface $wo): string {
    if ($wo->hasField('field_property') && !$wo->get('field_property')->isEmpty()) {
      $p = $wo->get('field_property')->entity;
      if ($p) {
        return $p->label();
      }
    }
    return '—';
  }

  private function propertyNickname(EntityInterface $wo): string {
    if ($wo->hasField('field_property') && !$wo->get('field_property')->isEmpty()) {
      $p = $wo->get('field_property')->entity;
      if ($p && $p->hasField('field_nickname') && !$p->get('field_nickname')->isEmpty()) {
        return (string) $p->get('field_nickname')->value;
      }
    }
    return '';
  }

  /* ---------- formatting ---------- */

  private function money(float $v): string {
    return '$' . number_format($v, 2);
  }

  private function formatDateTimeUs(int $ts): string {
    $dt = new \DateTime('@' . $ts);
    $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    return $dt->format('m/d/Y g:i A');
  }

}
