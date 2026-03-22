<?php

namespace Drupal\business_calendar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Generates payday business_calendar events on a rolling horizon.
 *
 * Payday anchor: Monday March 16, 2026. Every 14 days thereafter.
 * Generates paydays 6 months forward from today.
 * Idempotent — skips dates that already exist.
 * Flags conflicts with existing holiday events.
 */
class BusinessCalendarPaydayController extends ControllerBase {

  // Payday anchor — Monday March 16, 2026.
  const PAYDAY_ANCHOR = '2026-03-16';

  // Interval in days.
  const PAYDAY_INTERVAL = 14;

  // How far forward to generate (days).
  const HORIZON_DAYS = 180;

  protected Connection $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * Generates payday records and redirects back with a message.
   */
  public function generate(Request $request): RedirectResponse {
    $site_tz = new \DateTimeZone('America/Denver');
    $today   = new \DateTime('now', $site_tz);
    $horizon = (clone $today)->modify('+' . self::HORIZON_DAYS . ' days');
    $anchor  = new \DateTime(self::PAYDAY_ANCHOR, $site_tz);

    // Advance anchor to first payday on or after today.
    while ($anchor < $today) {
      $anchor->modify('+' . self::PAYDAY_INTERVAL . ' days');
    }

    $storage   = $this->entityTypeManager()->getStorage('business_calendar');
    $created   = 0;
    $skipped   = 0;
    $conflicts = 0;

    $cursor = clone $anchor;
    while ($cursor <= $horizon) {
      $date_str = $cursor->format('Y-m-d');
      $ts       = $cursor->setTime(0, 0, 0)->getTimestamp();

      // Check if payday already exists for this date.
      $existing = $this->database->select('business_calendar_field_data', 'bc')
        ->fields('bc', ['id'])
        ->condition('bc.id', $this->getPaydayIdsForDate($ts), 'IN')
        ->countQuery()
        ->execute()
        ->fetchField();

      if (!$existing) {
        // Check for holiday conflict.
        $holiday_conflict = $this->hasHolidayOnDate($ts);

        $entity = $storage->create([
          'type'                   => 'event',
          'title'                  => 'Payday' . ($holiday_conflict ? ' ⚠ Check Date' : ''),
          'field_date'             => ['value' => $ts, 'end_value' => $ts, 'duration' => 1439, 'all_day' => TRUE],
          'field_event_type'       => 'payday',
          'field_notes'            => $holiday_conflict ? 'Payday lands on or near a holiday — office should verify date.' : '',
          'field_is_auto_generated' => TRUE,
        ]);
        $entity->save();
        $created++;

        if ($holiday_conflict) {
          $conflicts++;
        }
      }
      else {
        $skipped++;
      }

      $cursor->modify('+' . self::PAYDAY_INTERVAL . ' days');
    }

    $this->messenger()->addStatus(
      "Paydays generated: {$created} created, {$skipped} already existed, {$conflicts} conflict(s) with holidays."
    );

    return new RedirectResponse('/admin/content');
  }

  /**
   * Returns IDs of payday business_calendar entities for a given timestamp.
   */
  protected function getPaydayIdsForDate(int $ts): array {
    // Get all payday entity IDs for this date.
    $query = $this->database->select('business_calendar__field_event_type', 'fet');
    $query->join('business_calendar__field_date', 'fd', 'fd.entity_id = fet.entity_id AND fd.deleted = 0');
    $query->fields('fet', ['entity_id']);
    $query->condition('fet.field_event_type_value', 'payday');
    $query->condition('fd.field_date_value', $ts);
    $ids = $query->execute()->fetchCol();
    return $ids ?: [0];
  }

  /**
   * Checks if a holiday exists on the given date.
   */
  protected function hasHolidayOnDate(int $ts): bool {
    $query = $this->database->select('business_calendar__field_event_type', 'fet');
    $query->join('business_calendar__field_date', 'fd', 'fd.entity_id = fet.entity_id AND fd.deleted = 0');
    $query->fields('fet', ['entity_id']);
    $query->condition('fet.field_event_type_value', 'holiday');
    $query->condition('fd.field_date_value', $ts);
    return (bool) $query->countQuery()->execute()->fetchField();
  }

}
