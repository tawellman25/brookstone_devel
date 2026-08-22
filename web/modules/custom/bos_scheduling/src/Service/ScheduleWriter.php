<?php

declare(strict_types=1);

namespace Drupal\bos_scheduling\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Single creation path for scheduling:work_order records.
 *
 * Extracted verbatim from SprinklerSchedulingController::save() so the Sprinkler
 * Bulk Scheduling tool and the winterizing carry-forward command share ONE
 * writer. The default create is byte-for-byte what the controller did: a
 * field_date smart-date all-day span (duration 1439), field_assigned_to,
 * field_scheduled_oder, field_scheduled_firm = FALSE, then work_order
 * .field_scheduled = TRUE.
 *
 * The legacy field_scheduled_date_and_time daterange and the all-day flag are
 * NOT written here — wo_schedule's presave "Smart Date → Date Range sync"
 * back-fills the daterange from field_date, and custom_date_all_day sets the
 * flag, on every scheduling save. Writing them here would duplicate (and risk
 * conflicting with) those hooks.
 *
 * wo_schedule also emits the wo_status_updates record (→ field_status) and the
 * schedule_insert note on insert; field_status is NEVER written directly.
 */
final class ScheduleWriter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
  ) {}

  /**
   * Does this work order already have a (non-deleted) scheduling record?
   *
   * Reproduces the controller's idempotency query — direct DB read (no clean
   * entity-API path to the field table's deleted flag).
   */
  public function hasSchedule(int $woId): bool {
    return (bool) $this->database->select('scheduling__field_work_order', 'swo')
      ->fields('swo', ['entity_id'])
      ->condition('swo.field_work_order_target_id', $woId)
      ->condition('swo.deleted', 0)
      ->execute()->fetchField();
  }

  /**
   * Create a scheduling record + flip work_order.field_scheduled.
   *
   * @param int $woId
   *   Work order id.
   * @param int $startTs
   *   Local-midnight Unix timestamp of the scheduled day.
   * @param array $opts
   *   Optional: teammate_uid (int|null — omit/blank = unassigned bucket),
   *   order (int|null → field_scheduled_oder), firm (bool, default FALSE),
   *   scheduling_note (string|null), notify (bool|null →
   *   field_notify_assigned_teammate), uid (int|null → "assigned by").
   *
   * @return array{status: string, scheduling_id: int|null}
   *   status: 'ok' | 'skipped' (already scheduled).
   */
  public function schedule(int $woId, int $startTs, array $opts = []): array {
    if ($this->hasSchedule($woId)) {
      return ['status' => 'skipped', 'scheduling_id' => NULL];
    }
    $values = [
      'type' => 'work_order',
      'field_work_order' => ['target_id' => $woId],
      'field_date' => [
        'value' => $startTs,
        'end_value' => $startTs + 86340,
        'duration' => 1439,
      ],
      'field_scheduled_firm' => !empty($opts['firm']),
    ];
    if (!empty($opts['teammate_uid'])) {
      $values['field_assigned_to'] = ['target_id' => (int) $opts['teammate_uid']];
    }
    if (array_key_exists('order', $opts) && $opts['order'] !== NULL && $opts['order'] !== '') {
      $values['field_scheduled_oder'] = (int) $opts['order'];
    }
    if (array_key_exists('scheduling_note', $opts) && $opts['scheduling_note'] !== NULL) {
      $values['field_scheduling_note'] = $opts['scheduling_note'];
    }
    if (array_key_exists('notify', $opts) && $opts['notify'] !== NULL) {
      $values['field_notify_assigned_teammate'] = (bool) $opts['notify'];
    }
    if (!empty($opts['uid'])) {
      $values['uid'] = (int) $opts['uid'];
    }

    $scheduling = $this->entityTypeManager->getStorage('scheduling')->create($values);
    $scheduling->save();

    $wo = $this->entityTypeManager->getStorage('work_order')->load($woId);
    if ($wo) {
      $wo->set('field_scheduled', TRUE);
      $wo->save();
    }
    return ['status' => 'ok', 'scheduling_id' => (int) $scheduling->id()];
  }

}
