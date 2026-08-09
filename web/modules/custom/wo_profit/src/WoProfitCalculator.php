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

  /**
   * Bundles with a standard "hours × billable rate + materials + rentals" live
   * revenue projection, mapped to their business_setting rate/increment/minimum
   * fields. Add a bundle here once its billing is verified to fit this shape;
   * structurally different services (snow per-push, spray per-gallon) need their
   * own method instead. Bundles not listed (and without an estimate) show
   * cost-so-far only.
   */
  const LIVE_REVENUE_BUNDLES = [
    'landscaping' => [
      'rate' => 'field_maintenance_crew_labor',
      'increment' => 'field_hour_billing_increment',
      'minimum' => 'field_general_minimum_time',
    ],
  ];

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
   * A live REVENUE projection for an in-progress WO (before sign-off computes
   * field_wo_total).
   *
   * Priority: a linked estimate / quoted price (any bundle) → else the bundle's
   * own billing formula (currently landscaping; extend via LIVE_REVENUE_BUNDLES).
   *
   * @return array|null
   *   ['revenue' => float, 'source' => 'estimate'|'computed'] or NULL if no
   *   projection is available for this bundle (→ panel shows cost-so-far).
   */
  public function liveRevenue(EntityInterface $wo): ?array {
    $est = $this->estimateRevenue($wo);
    if ($est !== NULL && $est > 0) {
      return ['revenue' => $est, 'source' => 'estimate'];
    }
    $cfg = self::LIVE_REVENUE_BUNDLES[$wo->bundle()] ?? NULL;
    if (!$cfg) {
      return NULL;
    }
    $rev = $this->laborRevenue($wo, $cfg)
      + $this->materialRevenue((int) $wo->id())
      + $this->rentalCost((int) $wo->id())
      + $this->ownedEquipment((int) $wo->id())['revenue'];
    return $rev > 0 ? ['revenue' => $rev, 'source' => 'computed'] : NULL;
  }

  /**
   * Revenue from a linked estimate / quoted price, if any (NULL if none).
   */
  protected function estimateRevenue(EntityInterface $wo): ?float {
    if ($wo->hasField('field_estimated_price') && !$wo->get('field_estimated_price')->isEmpty()) {
      $p = (float) $wo->get('field_estimated_price')->value;
      if ($p > 0) {
        return $p;
      }
    }
    if ($wo->hasField('field_estimate') && !$wo->get('field_estimate')->isEmpty()) {
      $e = $wo->get('field_estimate')->entity;
      if ($e && $e->hasField('field_estimate_total') && !$e->get('field_estimate_total')->isEmpty()) {
        $t = (float) $e->get('field_estimate_total')->value;
        if ($t > 0) {
          return $t;
        }
      }
    }
    return NULL;
  }

  /**
   * Live labor REVENUE for a standard hours × billable-rate bundle — replicates
   * the wo_* sign-off math (increment rounding + minimum floor) so the live
   * projection matches what completion would bill. 0 until any hours are logged.
   */
  protected function laborRevenue(EntityInterface $wo, array $cfg): float {
    $rate = $this->rate($cfg['rate']);
    if ($rate <= 0) {
      return 0.0;
    }
    $hours = $this->laborHours((int) $wo->id());
    if ($hours <= 0) {
      return 0.0;
    }
    $increment = $this->rate($cfg['increment']);
    $minimum = $this->rate($cfg['minimum']);
    if ($hours > $minimum && $increment > 0) {
      $increments = ceil(($hours * 60) / $increment);
      return $rate * ($increment / 60) * $increments;
    }
    return $rate * $minimum;
  }

  /**
   * Material REVENUE = Σ field_subtotal_w_markup (charged, incl. markup).
   */
  protected function materialRevenue(int $woId): float {
    $q = $this->database->select('wo_material_list_item__field_subtotal_w_markup', 'st');
    $q->join('wo_material_list_item__field_list_id', 'lid', 'st.entity_id = lid.entity_id AND lid.deleted = 0');
    $q->join('wo_material_list__field_work_order', 'wo', 'lid.field_list_id_target_id = wo.entity_id AND wo.deleted = 0');
    $q->condition('st.deleted', 0);
    $q->condition('wo.field_work_order_target_id', $woId);
    $q->addExpression('SUM(st.field_subtotal_w_markup_value)', 'r');
    return (float) $q->execute()->fetchField();
  }

  /**
   * A business_setting decimal value as float (0.0 if empty).
   */
  protected function rate(string $field): float {
    $val = $this->configPages->getValue('business_setting', $field);
    return isset($val[0]['value']) ? (float) $val[0]['value'] : 0.0;
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
   * OWNED equipment used on the WO (not rented) — cost + billable revenue.
   *
   * Owned rows = wo_rental_equipment with field_equipment_used set (a reference
   * to our own equipment) and NOT flagged rented. Cost = hours × the machine's
   * Operating Cost per Hour; revenue = hours × its Hourly Work Order Rate
   * (field_rate) when it is marked Billable. Currently only heavy_equipment /
   * small_engine carry these fields (Phase 1 scope).
   *
   * @return array
   *   ['cost' => float, 'revenue' => float]
   */
  public function ownedEquipment(int $woId): array {
    $q = $this->database->select('wo_rental_equipment__field_rented_for', 'rf');
    $q->condition('rf.field_rented_for_target_id', $woId);
    $q->condition('rf.deleted', 0);
    $q->join('wo_rental_equipment__field_equipment_used', 'eu', 'eu.entity_id = rf.entity_id AND eu.deleted = 0');
    $q->leftJoin('wo_rental_equipment__field_equipment_rented', 'rented', 'rented.entity_id = rf.entity_id AND rented.deleted = 0');
    $q->leftJoin('wo_rental_equipment__field_hours', 'h', 'h.entity_id = rf.entity_id AND h.deleted = 0');
    $q->leftJoin('equipment__field_rate', 'r', 'r.entity_id = eu.field_equipment_used_target_id AND r.deleted = 0');
    $q->leftJoin('equipment__field_operating_cost_per_hour', 'oc', 'oc.entity_id = eu.field_equipment_used_target_id AND oc.deleted = 0');
    $q->leftJoin('equipment__field_billable', 'b', 'b.entity_id = eu.field_equipment_used_target_id AND b.deleted = 0');
    // Owned = the "rented" flag is off/unset.
    $owned = $q->orConditionGroup()
      ->condition('rented.field_equipment_rented_value', 0)
      ->isNull('rented.field_equipment_rented_value');
    $q->condition($owned);
    $q->addField('h', 'field_hours_value', 'hours');
    $q->addField('r', 'field_rate_value', 'rate');
    $q->addField('oc', 'field_operating_cost_per_hour_value', 'opcost');
    $q->addField('b', 'field_billable_value', 'billable');

    $cost = 0.0;
    $revenue = 0.0;
    foreach ($q->execute() as $row) {
      $hours = (float) $row->hours;
      if ($hours <= 0) {
        continue;
      }
      $cost += $hours * (float) $row->opcost;
      if ((int) $row->billable === 1) {
        $revenue += $hours * (float) $row->rate;
      }
    }
    return ['cost' => $cost, 'revenue' => $revenue];
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
