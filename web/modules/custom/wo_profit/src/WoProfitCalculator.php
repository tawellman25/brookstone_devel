<?php

namespace Drupal\wo_profit;

use Drupal\config_pages\ConfigPagesLoaderService;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;

/**
 * Computes job-level cost & gross profit for a work order.
 *
 * Cost model (mirrors the existing wo_* billing rollups, swapping charged →
 * cost):
 *   - labor    = field_total_time (hrs) × blended labor cost/hr (business_setting)
 *   - materials= Σ wo_material_list_item.field_subtotal  (cost basis = unit cost
 *                × qty; the *charged* side uses field_subtotal_w_markup, so the
 *                difference is the markup margin)
 *   - chemicals= Σ wo_chemicals_used.field_subtotal (chemicals have no markup
 *                field — charged at cost, so this nets out of revenue)
 *   - rentals  = Σ COALESCE(receipt_total_cost, hourly_rate × hours)  (pass-through)
 *   - dump     = field_dump_fee_total  (pass-through)
 *
 * profit = field_wo_total (revenue) − total cost.
 *
 * This is JOB-LEVEL GROSS MARGIN — it deliberately excludes fuel/vehicle wear
 * (the trip fee sits in revenue with no cost line), company overhead, and
 * equipment depreciation. The UI must say so.
 */
class WoProfitCalculator {

  public function __construct(
    protected Connection $database,
    protected ConfigPagesLoaderService $configPages,
  ) {}

  /**
   * The blended loaded labor cost per hour (0.0 if unset).
   */
  public function blendedLaborRate(): float {
    $val = $this->configPages->getValue('business_setting', 'field_blended_labor_cost');
    return isset($val[0]['value']) ? (float) $val[0]['value'] : 0.0;
  }

  /**
   * Full cost/profit breakdown for a work order.
   *
   * @return array
   *   Keyed: hours, rate, labor, materials, chemicals, rentals, dump,
   *   total_cost, revenue, profit, margin (float %|null).
   */
  public function calculate(EntityInterface $wo): array {
    $id = (int) $wo->id();

    // Sum the actual time-clock entries (live) rather than the WO's
    // field_total_time roll-up — that roll-up is only filled at sign-off, so
    // reading it would show 0 labor for an in-progress WO that already has
    // hours logged. At completion the two are equal.
    $hours = $this->laborHours($id);
    $rate = $this->blendedLaborRate();
    $labor = $hours * $rate;

    $materials = $this->materialCost($id);
    $chemicals = $this->chemicalCost($id);
    $rentals = $this->rentalCost($id);
    $dump = $this->decimal($wo, 'field_dump_fee_total');

    $total_cost = $labor + $materials + $chemicals + $rentals + $dump;
    $revenue = $this->decimal($wo, 'field_wo_total');
    $profit = $revenue - $total_cost;
    $margin = $revenue > 0 ? ($profit / $revenue * 100) : NULL;

    return [
      'hours' => $hours,
      'rate' => $rate,
      'labor' => $labor,
      'materials' => $materials,
      'chemicals' => $chemicals,
      'rentals' => $rentals,
      'dump' => $dump,
      'total_cost' => $total_cost,
      'revenue' => $revenue,
      'profit' => $profit,
      'margin' => $margin,
    ];
  }

  /**
   * Read a decimal field value as float (0.0 if empty/missing).
   */
  protected function decimal(EntityInterface $wo, string $field): float {
    return $wo->hasField($field) && !$wo->get($field)->isEmpty()
      ? (float) $wo->get($field)->value
      : 0.0;
  }

  /**
   * Labor hours = Σ wo_time_clock.field_total_time for this WO (live).
   *
   * Same source the wo_* billing modules use for field_labor_total, so live and
   * frozen labor agree.
   */
  protected function laborHours(int $woId): float {
    $q = $this->database->select('wo_time_clock__field_work_order', 'wo');
    $q->condition('wo.field_work_order_target_id', $woId);
    $q->condition('wo.deleted', 0);
    $q->join('wo_time_clock__field_total_time', 'tt', 'tt.entity_id = wo.entity_id AND tt.deleted = 0');
    $q->addExpression('SUM(tt.field_total_time_value)', 'h');
    return (float) $q->execute()->fetchField();
  }

  /**
   * Material cost = Σ field_subtotal (cost basis) for this WO's material lists.
   */
  protected function materialCost(int $woId): float {
    $q = $this->database->select('wo_material_list_item__field_subtotal', 'st');
    $q->join('wo_material_list_item__field_list_id', 'lid', 'st.entity_id = lid.entity_id AND lid.deleted = 0');
    $q->join('wo_material_list__field_work_order', 'wo', 'lid.field_list_id_target_id = wo.entity_id AND wo.deleted = 0');
    $q->condition('st.deleted', 0);
    $q->condition('wo.field_work_order_target_id', $woId);
    $q->addExpression('SUM(st.field_subtotal_value)', 'c');
    return (float) $q->execute()->fetchField();
  }

  /**
   * Chemical cost = Σ field_subtotal for wo_chemicals_used on this WO.
   */
  protected function chemicalCost(int $woId): float {
    $q = $this->database->select('wo_chemicals_used__field_work_order', 'cwo');
    $q->condition('cwo.field_work_order_target_id', $woId);
    $q->condition('cwo.deleted', 0);
    $q->join('wo_chemicals_used__field_subtotal', 'st', 'st.entity_id = cwo.entity_id AND st.deleted = 0');
    $q->addExpression('SUM(st.field_subtotal_value)', 'c');
    return (float) $q->execute()->fetchField();
  }

  /**
   * Rental cost = Σ COALESCE(receipt_total_cost, hourly_rate × hours).
   */
  protected function rentalCost(int $woId): float {
    $q = $this->database->select('wo_rental_equipment__field_rented_for', 'wrf');
    $q->condition('wrf.field_rented_for_target_id', $woId);
    $q->condition('wrf.deleted', 0);
    $q->leftJoin('wo_rental_equipment__field_hourly_rate', 'hr', 'hr.entity_id = wrf.entity_id AND hr.deleted = 0');
    $q->leftJoin('wo_rental_equipment__field_hours', 'h', 'h.entity_id = wrf.entity_id AND h.deleted = 0');
    $q->leftJoin('wo_rental_equipment__field_receipt_total_cost', 'rtc', 'rtc.entity_id = wrf.entity_id AND rtc.deleted = 0');
    $q->addExpression('SUM(COALESCE(rtc.field_receipt_total_cost_value, hr.field_hourly_rate_value * h.field_hours_value, 0))', 'c');
    return (float) $q->execute()->fetchField();
  }

}
