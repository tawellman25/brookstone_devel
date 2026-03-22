<?php

namespace Drupal\business_calendar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns business calendar events as FullCalendar background events.
 *
 * These render as background shading on calendar day cells.
 * Used for holidays, closures, paydays, and company events.
 *
 * Query parameters:
 *   start — ISO date string (FullCalendar range start)
 *   end   — ISO date string (FullCalendar range end)
 */
class BusinessCalendarEventsController extends ControllerBase {

  protected Connection $database;

  /**
   * Background colors per event type.
   */
  const EVENT_COLORS = [
    'holiday'       => '#ffd5d5',
    'closure'       => '#d5e8ff',
    'payday'        => '#d5ffd5',
    'company_event' => '#fff3d5',
  ];

  /**
   * Text colors per event type (for readability).
   */
  const TEXT_COLORS = [
    'holiday'       => '#8b0000',
    'closure'       => '#003366',
    'payday'        => '#1a5c1a',
    'company_event' => '#7a5500',
  ];

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * Returns FullCalendar-compatible background event JSON.
   */
  public function events(Request $request): JsonResponse {
    $start = $request->query->get('start');
    $end   = $request->query->get('end');

    if (!$start || !$end) {
      return new JsonResponse([]);
    }

    // Convert FullCalendar ISO range to Unix timestamps (site timezone).
    $site_tz  = new \DateTimeZone('America/Denver');
    $start_ts = (new \DateTime($start, $site_tz))->getTimestamp();
    $end_ts   = (new \DateTime($end, $site_tz))->getTimestamp();

    // Query business_calendar entities in range.
    // field_date is smartdate (Unix timestamps).
    $query = $this->database->select('business_calendar_field_data', 'bc');
    $query->fields('bc', ['id', 'title']);

    $query->join(
      'business_calendar__field_date',
      'fd',
      'fd.entity_id = bc.id AND fd.deleted = 0'
    );
    $query->addField('fd', 'field_date_value', 'date_ts');

    $query->join(
      'business_calendar__field_event_type',
      'fet',
      'fet.entity_id = bc.id AND fet.deleted = 0'
    );
    $query->addField('fet', 'field_event_type_value', 'event_type');

    $query->leftJoin(
      'business_calendar__field_notes',
      'fn',
      'fn.entity_id = bc.id AND fn.deleted = 0'
    );
    $query->addField('fn', 'field_notes_value', 'notes');

    // Filter to requested date range.
    $query->condition('fd.field_date_value', $start_ts, '>=');
    $query->condition('fd.field_date_value', $end_ts, '<');

    $query->orderBy('fd.field_date_value', 'ASC');

    $results = $query->execute()->fetchAll();

    $events = [];
    foreach ($results as $row) {
      $event_type = $row->event_type ?? 'company_event';
      $bg_color   = self::EVENT_COLORS[$event_type] ?? '#f0f0f0';
      $text_color = self::TEXT_COLORS[$event_type] ?? '#333333';

      // Convert timestamp to date string (site timezone).
      $date_str = (new \DateTime('@' . $row->date_ts))
        ->setTimezone($site_tz)
        ->format('Y-m-d');

      $events[] = [
        'id'          => 'biz_' . $row->id,
        'title'       => $row->title,
        'start'       => $date_str,
        'end'         => $date_str,
        'allDay'      => TRUE,
        'display'     => 'background',
        'backgroundColor' => $bg_color,
        'textColor'   => $text_color,
        'extendedProps' => [
          'eventType'  => $event_type,
          'notes'      => $row->notes ?? '',
          'background' => TRUE,
        ],
      ];
    }

    return new JsonResponse($events);
  }

}
