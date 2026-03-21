<?php

namespace Drupal\admin_calendar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles drag-drop rescheduling of scheduling entities from the calendar.
 *
 * Receives a POST with { date: 'YYYY-MM-DD', all_day: bool }
 * Updates field_scheduled_date_and_time and field_date on the scheduling entity.
 *
 * Governance:
 * - Only updates scheduling entities in non-completed WO states.
 * - Logs the reschedule action.
 * - Does not touch WO execution data.
 */
class AdminCalendarRescheduleController extends ControllerBase {

  /**
   * Reschedules a scheduling entity to a new date.
   */
  public function reschedule(Request $request, int $scheduling_id): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);

    if (empty($data['date'])) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Missing date parameter.'], 400);
    }

    $date_str = $data['date'];
    $all_day  = !empty($data['all_day']);

    // Validate date format.
    $date = \DateTime::createFromFormat('Y-m-d', substr($date_str, 0, 10));
    if (!$date) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Invalid date format.'], 400);
    }

    // Load the scheduling entity.
    $storage = $this->entityTypeManager()->getStorage('scheduling');
    $scheduling = $storage->load($scheduling_id);

    if (!$scheduling) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Scheduling record not found.'], 404);
    }

    // Safety: do not reschedule if WO is Complete/Invoiced/Paid.
    $wo_ref = $scheduling->get('field_work_order')->referencedEntities();
    if (!empty($wo_ref)) {
      $wo = reset($wo_ref);
      $status_refs = $wo->get('field_status')->referencedEntities();
      if (!empty($status_refs)) {
        $status_tid = (int) reset($status_refs)->id();
        if (in_array($status_tid, [1097, 1283, 1281, 1504])) {
          return new JsonResponse([
            'success' => FALSE,
            'message' => 'Cannot reschedule a completed or invoiced Work Order.',
          ], 403);
        }
      }
    }

    $date_iso = $date->format('Y-m-d');

    // Update field_scheduled_date_and_time (daterange — ISO strings).
    $scheduling->set('field_scheduled_date_and_time', [
      'value'     => $date_iso,
      'end_value' => $date_iso,
      'all_day'   => $all_day ? TRUE : FALSE,
    ]);

    // Keep field_date (smartdate — Unix timestamps) in sync.
    $start_ts = $date->setTime(0, 0, 0)->getTimestamp();
    $end_ts   = $date->setTime(23, 59, 0)->getTimestamp();
    $scheduling->set('field_date', [
      'value'      => $start_ts,
      'end_value'  => $end_ts,
      'duration'   => 1439,
    ]);

    $scheduling->save();

    \Drupal::logger('admin_calendar')->info(
      'Scheduling entity @id rescheduled to @date by user @uid.',
      ['@id' => $scheduling_id, '@date' => $date_iso, '@uid' => \Drupal::currentUser()->id()]
    );

    return new JsonResponse(['success' => TRUE, 'date' => $date_iso]);
  }

}
