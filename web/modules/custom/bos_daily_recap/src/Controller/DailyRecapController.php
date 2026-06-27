<?php

declare(strict_types=1);

namespace Drupal\bos_daily_recap\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Daily Recap dashboard: yesterday's completions + value per department.
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
   * Builds the dashboard render array.
   */
  public function view(): array {
    $windows = $this->windows();

    $window_out = [];
    foreach ($windows as $key => $w) {
      $wo_ids = $this->completedWorkOrderIds($w['start'], $w['end']);
      $summary = $this->summarize($wo_ids);
      $total_value = 0.0;
      $total_count = 0;
      $rows = [];
      foreach ($summary as $dept => $d) {
        $total_value += $d['value'];
        $total_count += $d['count'];
        $rows[] = [
          'dept' => $dept,
          'value' => $this->money($d['value']),
          'count' => $d['count'],
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

    return [
      '#theme' => 'daily_recap',
      '#generated_at' => $this->formatDateTimeUs($this->time->getRequestTime()),
      '#yesterday_label' => $windows['yesterday']['label'],
      '#windows' => $window_out,
      '#yesterday_completions' => $this->completions($windows['yesterday']['start'], $windows['yesterday']['end']),
      '#mowing_note' => 'Lawn mowing is contract-billed, so its dollar value is not carried on the work order — the per-department $ shows ~$0 for mowing; use the completions count for mowing volume.',
      '#attached' => ['library' => ['bos_daily_recap/dashboard']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * The three reporting windows as [start_ts, end_ts] in the site timezone.
   */
  private function windows(): array {
    $tz = new \DateTimeZone(date_default_timezone_get());
    $now = new \DateTime('@' . $this->time->getRequestTime());
    $now->setTimezone($tz);

    $y_start = (clone $now)->modify('yesterday')->setTime(0, 0, 0);
    $y_end = (clone $y_start)->setTime(23, 59, 59);

    // Week-to-date, Sunday start.
    $dow = (int) $now->format('w'); // 0 = Sunday.
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

  /**
   * Distinct WO ids completed within [start, end] (deduped across complete_info).
   */
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

  /**
   * Per-department value + count for the given WO ids (warranty-excluded).
   */
  private function summarize(array $wo_ids): array {
    $by_dept = [];
    foreach (array_chunk($wo_ids, 300) as $chunk) {
      foreach ($this->etm->getStorage('work_order')->loadMultiple($chunk) as $wo) {
        if ((int) ($wo->get('field_status')->target_id ?? 0) === self::STATUS_WARRANTIED) {
          continue;
        }
        $dept = $this->resolveDepartment($wo);
        $value = $this->woTotal($wo);
        if (!isset($by_dept[$dept])) {
          $by_dept[$dept] = ['value' => 0.0, 'count' => 0];
        }
        $by_dept[$dept]['value'] += $value;
        $by_dept[$dept]['count']++;
      }
    }
    ksort($by_dept);
    return $by_dept;
  }

  /**
   * Detailed completions list for a window (deduped by WO, latest completion).
   */
  private function completions(int $start, int $end): array {
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
        $by_wo[$wid] = [
          '_ts' => $ts,
          'wo' => $this->woNumber($wo),
          'service' => $this->serviceLabel($wo),
          'property' => $this->propertyLabel($wo),
          'dept' => $this->resolveDepartment($wo),
          'value' => $this->money($this->woTotal($wo)),
          'completed_at' => $this->formatDateTimeUs($ts),
        ];
      }
    }
    // Newest completion first.
    usort($by_wo, fn($a, $b) => $b['_ts'] <=> $a['_ts']);
    foreach ($by_wo as &$r) {
      unset($r['_ts']);
    }
    return array_values($by_wo);
  }

  /* ---------- resolvers ---------- */

  private function resolveDepartment(EntityInterface $wo): string {
    if (!$wo->hasField('field_service') || $wo->get('field_service')->isEmpty()) {
      return 'Unassigned';
    }
    $term = $wo->get('field_service')->entity;
    if (!$term || !$term->hasField('field_department') || $term->get('field_department')->isEmpty()) {
      return 'Unassigned';
    }
    $dept = $term->get('field_department')->entity;
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

  /* ---------- formatting (BOS US date standard, site tz) ---------- */

  private function money(float $v): string {
    return '$' . number_format($v, 2);
  }

  private function formatDateTimeUs(int $ts): string {
    $dt = new \DateTime('@' . $ts);
    $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    return $dt->format('m/d/Y g:i A');
  }

}
