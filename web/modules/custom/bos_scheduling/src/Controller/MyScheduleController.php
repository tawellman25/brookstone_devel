<?php

namespace Drupal\bos_scheduling\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Renders the crew daily schedule page.
 *
 * Shows Work Orders assigned to the current user for a given date,
 * sorted by field_scheduled_oder (route order).
 *
 * Skips empty days — navigates to next/previous day that has WOs.
 *
 * Query param: date (Y-m-d) — defaults to today.
 */
class MyScheduleController extends ControllerBase {

  protected Connection $database;

  // WO statuses that appear on crew schedule (per work_order_status.md).
  const VISIBLE_STATUSES = [1089, 1099, 1095, 1503, 1091, 1090, 1092, 1093, 1094, 1096];

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * Renders the daily schedule page.
   */
  public function page(Request $request): array {
    $site_tz = new \DateTimeZone(date_default_timezone_get());
    $uid     = (int) \Drupal::currentUser()->id();

    // Get requested date or default to today.
    $date_param = $request->query->get('date');
    if ($date_param && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_param)) {
      $current = new \DateTime($date_param, $site_tz);
    }
    else {
      $current = new \DateTime('today', $site_tz);
    }

    // Load WOs for this date.
    $items = $this->getScheduleItems($uid, $current, $site_tz);

    // Find prev/next days with WOs.
    $prev_date = $this->findAdjacentDate($uid, $current, $site_tz, 'prev');
    $next_date = $this->findAdjacentDate($uid, $current, $site_tz, 'next');

    // Format date label.
    $today     = new \DateTime('today', $site_tz);
    $tomorrow  = new \DateTime('tomorrow', $site_tz);
    $date_label = match(TRUE) {
      $current->format('Y-m-d') === $today->format('Y-m-d')    => 'Today — ' . $current->format('l, F j'),
      $current->format('Y-m-d') === $tomorrow->format('Y-m-d') => 'Tomorrow — ' . $current->format('l, F j'),
      default => $current->format('l, F j, Y'),
    };

    return [
      '#theme'      => 'bos_scheduling_my_schedule',
      '#date'       => $current->format('Y-m-d'),
      '#date_label' => $date_label,
      '#prev_date'  => $prev_date,
      '#next_date'  => $next_date,
      '#items'      => $items,
      '#empty'      => empty($items),
      '#attached'   => [
        'library' => ['bos_scheduling/my_schedule'],
      ],
    ];
  }

  /**
   * Loads scheduled WOs for a user on a given date.
   */
  protected function getScheduleItems(int $uid, \DateTime $date, \DateTimeZone $tz): array {
    $start_ts = (clone $date)->setTime(0, 0, 0)->getTimestamp();
    $end_ts   = (clone $date)->setTime(23, 59, 59)->getTimestamp();

    $query = $this->database->select('scheduling_field_data', 's');
    $query->fields('s', ['id']);

    // Date filter.
    $query->join(
      'scheduling__field_date',
      'fd',
      's.id = fd.entity_id AND fd.deleted = 0'
    );
    $query->condition('fd.field_date_value', $start_ts, '>=');
    $query->condition('fd.field_date_value', $end_ts, '<=');

    // Assigned to current user.
    $query->join(
      'scheduling__field_assigned_to',
      'sat',
      's.id = sat.entity_id AND sat.deleted = 0'
    );
    $query->condition('sat.field_assigned_to_target_id', $uid);

    // Work order.
    $query->join(
      'scheduling__field_work_order',
      'swo',
      's.id = swo.entity_id AND swo.deleted = 0'
    );
    $query->addField('swo', 'field_work_order_target_id', 'wo_id');

    // WO status — only show visible statuses.
    $query->leftJoin(
      'work_order__field_status',
      'wos',
      'wos.entity_id = swo.field_work_order_target_id AND wos.deleted = 0'
    );
    $query->condition('wos.field_status_target_id', self::VISIBLE_STATUSES, 'IN');
    $query->addField('wos', 'field_status_target_id', 'status_tid');

    // Schedule order.
    $query->leftJoin(
      'scheduling__field_scheduled_oder',
      'sord',
      's.id = sord.entity_id AND sord.deleted = 0'
    );
    $query->addField('sord', 'field_scheduled_oder_value', 'schedule_order');

    // Firm flag.
    $query->leftJoin(
      'scheduling__field_scheduled_firm',
      'sfirm',
      's.id = sfirm.entity_id AND sfirm.deleted = 0'
    );
    $query->addField('sfirm', 'field_scheduled_firm_value', 'is_firm');

    // Scheduling note.
    $query->leftJoin(
      'scheduling__field_scheduling_note',
      'snote',
      's.id = snote.entity_id AND snote.deleted = 0'
    );
    $query->addField('snote', 'field_scheduling_note_value', 'scheduling_note');

    // Property nickname + details.
    $query->leftJoin(
      'work_order__field_property',
      'wop',
      'wop.entity_id = swo.field_work_order_target_id AND wop.deleted = 0'
    );
    $query->addField('wop', 'field_property_target_id', 'property_id');

    $query->leftJoin(
      'properties__field_nickname',
      'nick',
      'nick.entity_id = wop.field_property_target_id AND nick.deleted = 0'
    );
    $query->addField('nick', 'field_nickname_value', 'property_nickname');

    $query->leftJoin(
      'properties__field_full_address',
      'addr',
      'addr.entity_id = wop.field_property_target_id AND addr.deleted = 0'
    );
    $query->addField('addr', 'field_full_address_value', 'full_address');

    $query->leftJoin(
      'properties__field_gate_code',
      'gate',
      'gate.entity_id = wop.field_property_target_id AND gate.deleted = 0'
    );
    $query->addField('gate', 'field_gate_code_value', 'gate_code');

    $query->leftJoin(
      'properties__field_call_ahead',
      'call',
      'call.entity_id = wop.field_property_target_id AND call.deleted = 0'
    );
    $query->addField('call', 'field_call_ahead_value', 'call_ahead');

    $query->leftJoin(
      'properties__field_work_order_note',
      'wonote',
      'wonote.entity_id = wop.field_property_target_id AND wonote.deleted = 0'
    );
    $query->addField('wonote', 'field_work_order_note_value', 'wo_note');

    // Work to do description.
    $query->leftJoin(
      'work_order__field_work_todo_description',
      'wtd',
      'wtd.entity_id = swo.field_work_order_target_id AND wtd.deleted = 0'
    );
    $query->addField('wtd', 'field_work_todo_description_value', 'work_todo');

    // Aeration flag heads.
    $query->leftJoin(
      'work_order__field_aeration_flag_heads',
      'afh',
      'afh.entity_id = swo.field_work_order_target_id AND afh.deleted = 0'
    );
    $query->addField('afh', 'field_aeration_flag_heads_value', 'aeration_flag');

    // Service + SOP code.
    $query->leftJoin(
      'work_order__field_service',
      'wosvc',
      'wosvc.entity_id = swo.field_work_order_target_id AND wosvc.deleted = 0'
    );
    $query->leftJoin(
      'taxonomy_term_field_data',
      'svc',
      'svc.tid = wosvc.field_service_target_id'
    );
    $query->addField('svc', 'name', 'service_name');
    $query->leftJoin(
      'taxonomy_term__field_sop_code',
      'svccode',
      'svccode.entity_id = svc.tid AND svccode.deleted = 0'
    );
    $query->addField('svccode', 'field_sop_code_value', 'service_code');

    $query->orderBy('sord.field_scheduled_oder_value', 'ASC');
    $query->orderBy('fd.field_date_value', 'ASC');

    $results = $query->execute()->fetchAll();

    $status_labels = [
      1089 => 'Open',
      1099 => 'Needs Confirmed',
      1095 => 'Waiting for Customer',
      1503 => 'Accepted',
      1091 => 'Scheduled',
      1090 => 'Assigned',
      1092 => 'In Progress',
      1093 => 'Needs Parts',
      1094 => 'Parts Ordered',
      1096 => 'Needs Access',
    ];

    $items = [];
    foreach ($results as $row) {
      try {
        $wo_url = Url::fromRoute(
          'entity.work_order.canonical',
          ['work_order' => $row->wo_id]
        )->toString();
      }
      catch (\Exception $e) {
        $wo_url = '/';
      }

      $items[] = [
        'scheduling_id'   => $row->id,
        'wo_id'           => $row->wo_id,
        'wo_url'          => $wo_url,
        'schedule_order'  => (int) ($row->schedule_order ?? 0),
        'is_firm'         => (bool) ($row->is_firm ?? FALSE),
        'scheduling_note' => $row->scheduling_note ?? '',
        'status_tid'      => (int) ($row->status_tid ?? 0),
        'status_label'    => $status_labels[$row->status_tid] ?? 'Unknown',
        'property_nickname' => $row->property_nickname ?? 'Unknown Property',
        'full_address'    => $row->full_address ?? '',
        'gate_code'       => $row->gate_code ?? '',
        'call_ahead'      => (bool) ($row->call_ahead ?? FALSE),
        'wo_note'         => $row->wo_note ?? '',
        'work_todo'       => html_entity_decode(strip_tags($row->work_todo ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'aeration_flag'   => (bool) ($row->aeration_flag ?? FALSE),
        'service_name'    => $row->service_name ?? '',
        'service_code'    => strtoupper($row->service_code ?? '') ?: ($row->service_name ?? ''),
      ];
    }

    return $items;
  }

  /**
   * Finds the nearest prev/next date that has scheduled WOs for this user.
   */
  protected function findAdjacentDate(int $uid, \DateTime $current, \DateTimeZone $tz, string $direction): ?string {
    $modifier  = $direction === 'next' ? '+1 day' : '-1 day';
    $operator  = $direction === 'next' ? '>' : '<';
    $order     = $direction === 'next' ? 'ASC' : 'DESC';
    $limit_ts  = $direction === 'next'
      ? (clone $current)->setTime(23, 59, 59)->getTimestamp()
      : (clone $current)->setTime(0, 0, 0)->getTimestamp();

    // Search up to 60 days in either direction.
    $max_days  = 60;
    $candidate = clone $current;

    for ($i = 0; $i < $max_days; $i++) {
      $candidate->modify($modifier);
      $start_ts = (clone $candidate)->setTime(0, 0, 0)->getTimestamp();
      $end_ts   = (clone $candidate)->setTime(23, 59, 59)->getTimestamp();

      $count = $this->database->select('scheduling_field_data', 's')
        ->countQuery();
      // We need a subquery approach — use exists pattern.
      $exists = $this->database->select('scheduling__field_date', 'fd');
      $exists->join('scheduling__field_assigned_to', 'sat', 'sat.entity_id = fd.entity_id AND sat.deleted = 0');
      $exists->join('scheduling__field_work_order', 'swo', 'swo.entity_id = fd.entity_id AND swo.deleted = 0');
      $exists->join('work_order__field_status', 'wos', 'wos.entity_id = swo.field_work_order_target_id AND wos.deleted = 0');
      $exists->fields('fd', ['entity_id']);
      $exists->condition('fd.deleted', 0);
      $exists->condition('fd.field_date_value', $start_ts, '>=');
      $exists->condition('fd.field_date_value', $end_ts, '<=');
      $exists->condition('sat.field_assigned_to_target_id', $uid);
      $exists->condition('wos.field_status_target_id', self::VISIBLE_STATUSES, 'IN');
      $exists->range(0, 1);

      $result = $exists->execute()->fetchField();
      if ($result) {
        return $candidate->format('Y-m-d');
      }
    }

    return NULL;
  }

}
