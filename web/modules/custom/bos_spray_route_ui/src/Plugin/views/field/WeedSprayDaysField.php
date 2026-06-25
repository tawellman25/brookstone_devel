<?php

declare(strict_types=1);

namespace Drupal\bos_spray_route_ui\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Computed field: days since last weed spray with color status indicator.
 *
 * @ViewsField("weed_spray_days_field")
 */
class WeedSprayDaysField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function usesGroupBy(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // Computed field — no database query needed.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $values->_entity;
    if (!$entity || $entity->bundle() !== 'weed_spraying') {
      return '';
    }

    // Determine frequency threshold.
    $frequency_tid = NULL;
    if ($entity->hasField('field_beds_spraying_frequency')
        && !$entity->get('field_beds_spraying_frequency')->isEmpty()) {
      $frequency_tid = (int) $entity->get('field_beds_spraying_frequency')->target_id;
    }
    elseif ($entity->hasField('field_misc_spraying_frequency')
        && !$entity->get('field_misc_spraying_frequency')->isEmpty()) {
      $frequency_tid = (int) $entity->get('field_misc_spraying_frequency')->target_id;
    }

    // Frequency TID → threshold days.
    // 1104 = Monthly (35), 1105 = Biweekly (18), 1106 = On Call (no threshold).
    $thresholds = [
      1104 => 35,
      1105 => 18,
    ];
    $threshold = $thresholds[$frequency_tid] ?? NULL;

    // On Call — no schedule to measure against.
    if ($frequency_tid === 1106) {
      return [
        '#markup' => '<span class="spray-status spray-status--on-call">On Call</span>',
      ];
    }

    // Days are measured from the most recent VISIT — a spray
    // (field_last_applied_date) OR a no-spray check (field_last_checked). The
    // weed-spray sign-off stamps field_last_checked on every visit; counting
    // only actual sprays would let a "checked, no spray needed" visit still
    // read as overdue and never reset the clock.
    $now = new \DateTime();
    $last = NULL;
    foreach (['field_last_applied_date', 'field_last_checked'] as $field_name) {
      if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
        continue;
      }
      try {
        $candidate = new \DateTime($entity->get($field_name)->value);
      }
      catch (\Exception $e) {
        continue;
      }
      if ($last === NULL || $candidate > $last) {
        $last = $candidate;
      }
    }

    // Never visited — neither sprayed nor checked.
    if ($last === NULL) {
      return [
        '#markup' => '<span class="spray-status spray-status--never">Never Visited</span>',
      ];
    }

    $days = (int) $now->diff($last)->days;

    // Determine status class and label.
    if ($threshold === NULL) {
      // No frequency set — just show days.
      $status = 'ok';
      $label = $days . ' days';
    }
    elseif ($days > $threshold) {
      $status = 'overdue';
      $label = $days . ' days (OVERDUE)';
    }
    elseif ($days > ($threshold - 5)) {
      $status = 'due';
      $label = $days . ' days (Due Soon)';
    }
    else {
      $status = 'ok';
      $label = $days . ' days';
    }

    return [
      '#markup' => '<span class="spray-status spray-status--' . $status . '">' . $label . '</span>',
    ];
  }

}
