<?php

namespace Drupal\bos_scheduling\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sprinkler WO bulk scheduling tool.
 *
 * Path: /admin/office/work-orders/sprinkler/scheduling
 *
 * Lists unscheduled sprinkler WOs grouped by zipcode, sorted by street
 * address. Allows bulk selection and assignment of date + technician,
 * creating scheduling entities for all selected WOs in one action.
 */
class SprinklerSchedulingController extends ControllerBase {

  protected Connection $database;

  const BUNDLES = [
    'sprinkler_start_up'    => 'Start Up',
    'sprinkler_winterizing' => 'Winterizing',
    'sprinkler_check_up'    => 'Check Up',
    'sprinkler_repair'      => 'Repair',
    'backflow_testing'      => 'Backflow Testing',
    'sprinkler_design'      => 'Design',
    'sprinkler_installation'=> 'Installation',
  ];

  const BUNDLE_CODES = [
    'sprinkler_start_up'    => 'SSU',
    'sprinkler_winterizing' => 'WIN',
    'sprinkler_check_up'    => 'SCU',
    'sprinkler_repair'      => 'REP',
    'backflow_testing'      => 'BFT',
    'sprinkler_design'      => 'DES',
    'sprinkler_installation'=> 'INS',
  ];

  const ACTIVE_STATUSES = [1089, 1099, 1095, 1503, 1091, 1090, 1092, 1093, 1094, 1096];

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * Main page — listing and filter form.
   */
  public function page(Request $request): array {
    $bundles     = array_keys(self::BUNDLES);
    $filter_type = $request->query->get('type', '');
    $filter_zip  = $request->query->get('zip', '');
    $filter_status = $request->query->get('status', 'unscheduled');
    $filter_street = $request->query->get('street', '');
    $filter_sort   = $request->query->get('sort', 'fifo');

    if (!empty($filter_type) && isset(self::BUNDLES[$filter_type])) {
      $bundles = [$filter_type];
    }

    $rows      = $this->getWOs($bundles, $filter_zip, $filter_status, $filter_street, $filter_sort);
    $zips      = $this->getZips();
    $teammates = $this->getTeammates();
    $stats     = $this->getStats($bundles, $filter_zip);

    // Group rows by zipcode (or flat for FIFO).
    $grouped = [];
    foreach ($rows as $row) {
      if ($filter_sort === 'fifo') {
        $key = 'All WOs — oldest first';
      }
      else {
        $city = $row['city_name'] ?: '';
        $zip  = $row['zipcode'] ?: 'Unknown';
        $key  = $city ? $city . ' — ' . $zip : $zip;
      }
      $grouped[$key][] = $row;
    }

    return [
      '#theme'         => 'bos_scheduling_sprinkler',
      '#filter_type'   => $filter_type,
      '#filter_zip'    => $filter_zip,
      '#filter_status' => $filter_status,
      '#filter_street' => $filter_street,
      '#filter_sort'   => $filter_sort,
      '#bundles'       => self::BUNDLES,
      '#zips'          => $zips,
      '#teammates'     => $teammates,
      '#grouped'       => $grouped,
      '#stats'         => $stats,
      '#attached'      => [
        'library' => ['bos_scheduling/sprinkler_scheduling'],
        'drupalSettings' => [
          'bosSprinklerScheduling' => [
            'saveUrl' => '/admin/office/work-orders/scheduling/sprinkler/save',
          ],
        ],
      ],
    ];
  }

  /**
   * AJAX save endpoint — creates scheduling entities for selected WOs.
   */
  public function save(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);

    $wo_ids       = $data['wo_ids'] ?? [];
    $date_str     = $data['date'] ?? '';
    $teammate_uid = (int) ($data['teammate_uid'] ?? 0);
    $start_order  = (int) ($data['start_order'] ?? 1);

    if (empty($wo_ids) || empty($date_str) || empty($teammate_uid)) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Missing required fields.'], 400);
    }

    // Parse date.
    $site_tz = new \DateTimeZone(date_default_timezone_get());
    try {
      $dt = new \DateTime($date_str, $site_tz);
      $dt->setTime(0, 0, 0);
      $ts = $dt->getTimestamp();
    }
    catch (\Exception $e) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Invalid date.'], 400);
    }

    $storage   = $this->entityTypeManager()->getStorage('scheduling');
    $wo_storage = $this->entityTypeManager()->getStorage('work_order');
    $created   = 0;
    $skipped   = 0;
    $order     = $start_order;

    foreach ($wo_ids as $wo_id) {
      $wo_id = (int) $wo_id;

      // Check if scheduling record already exists for this WO on this date.
      $existing = $this->database->select('scheduling__field_work_order', 'swo')
        ->fields('swo', ['entity_id'])
        ->condition('swo.field_work_order_target_id', $wo_id)
        ->condition('swo.deleted', 0)
        ->execute()->fetchField();

      if ($existing) {
        $skipped++;
        continue;
      }

      // Create scheduling entity.
      $scheduling = $storage->create([
        'type'                => 'work_order',
        'field_work_order'    => ['target_id' => $wo_id],
        'field_date'          => [
          'value'     => $ts,
          'end_value' => $ts,
          'duration'  => 1439,
          'all_day'   => TRUE,
        ],
        'field_assigned_to'      => ['target_id' => $teammate_uid],
        'field_scheduled_oder'   => $order,
        'field_scheduled_firm'   => TRUE,
      ]);
      $scheduling->save();

      // Mark WO as scheduled.
      $wo = $wo_storage->load($wo_id);
      if ($wo) {
        $wo->set('field_scheduled', TRUE);
        $wo->save();
      }

      $created++;
      $order++;
    }

    return new JsonResponse([
      'success'  => TRUE,
      'created'  => $created,
      'skipped'  => $skipped,
      'message'  => "{$created} WOs scheduled" . ($skipped ? ", {$skipped} already had scheduling records (skipped)." : "."),
    ]);
  }

  /**
   * Loads WOs matching current filters.
   */
  protected function getWOs(array $bundles, string $zip, string $status, string $street, string $sort = 'city'): array {
    $query = $this->database->select('work_order', 'w');
    $query->fields('w', ['id', 'type']);
    $query->join('work_order_field_data', 'wfd', 'wfd.id = w.id');
    $query->addField('wfd', 'created', 'wo_created');

    // Status filter.
    $query->join('work_order__field_status', 'wos', 'wos.entity_id = w.id AND wos.deleted = 0');
    $query->addField('wos', 'field_status_target_id', 'status_tid');

    if ($status === 'unscheduled') {
      $query->condition('wos.field_status_target_id', self::ACTIVE_STATUSES, 'IN');
      // Exclude WOs that already have a scheduling record.
      $subquery = $this->database->select('scheduling__field_work_order', 'swo');
      $subquery->fields('swo', ['field_work_order_target_id']);
      $subquery->condition('swo.deleted', 0);
      $query->condition('w.id', $subquery, 'NOT IN');
    }
    else {
      $query->condition('wos.field_status_target_id', self::ACTIVE_STATUSES, 'IN');
    }

    // Bundle filter.
    $query->condition('w.type', $bundles, 'IN');

    // Property joins.
    $query->join('work_order__field_property', 'wop', 'wop.entity_id = w.id AND wop.deleted = 0');
    $query->addField('wop', 'field_property_target_id', 'property_id');

    $query->leftJoin('properties__field_nickname', 'nick', 'nick.entity_id = wop.field_property_target_id AND nick.deleted = 0');
    $query->addField('nick', 'field_nickname_value', 'property_nickname');

    $query->leftJoin('properties__field_street_address', 'addr', 'addr.entity_id = wop.field_property_target_id AND addr.deleted = 0');
    $query->addField('addr', 'field_street_address_value', 'street_address');

    $query->leftJoin('properties__field_full_address', 'faddr', 'faddr.entity_id = wop.field_property_target_id AND faddr.deleted = 0');
    $query->addField('faddr', 'field_full_address_value', 'full_address');

    // Zipcode + city name.
    $query->leftJoin('properties__field_zipcode_reference', 'pz', 'pz.entity_id = wop.field_property_target_id AND pz.deleted = 0');
    $query->leftJoin('zipcodes_field_data', 'z', 'z.id = pz.field_zipcode_reference_target_id');
    $query->addField('z', 'title', 'zipcode');
    $query->addField('z', 'id', 'zipcode_id');
    $query->leftJoin('zipcodes__field_city', 'zfc', 'zfc.entity_id = z.id AND zfc.deleted = 0');
    $query->leftJoin('city_field_data', 'citydata', 'citydata.id = zfc.field_city_target_id');
    $query->addField('citydata', 'title', 'city_name');

    // Aeration flag — read from stored boolean field.
    $query->leftJoin(
      'work_order__field_aeration_flag_heads',
      'afh',
      'afh.entity_id = w.id AND afh.deleted = 0'
    );
    $query->addField('afh', 'field_aeration_flag_heads_value', 'has_aeration');

    // Scheduled date — from scheduling entity via field_work_order.
    $query->leftJoin(
      'scheduling__field_work_order',
      'schwo',
      'schwo.field_work_order_target_id = w.id AND schwo.deleted = 0'
    );
    $query->leftJoin(
      'scheduling__field_date',
      'schfd',
      'schfd.entity_id = schwo.entity_id AND schfd.deleted = 0'
    );
    $query->addField('schfd', 'field_date_value', 'scheduled_ts');
    $query->leftJoin(
      'scheduling__field_assigned_to',
      'schsat',
      'schsat.entity_id = schwo.entity_id AND schsat.deleted = 0'
    );
    $query->leftJoin(
      'users_field_data',
      'schu',
      'schu.uid = schsat.field_assigned_to_target_id'
    );
    $query->addField('schu', 'uid', 'scheduled_uid');

    // Total zones via sprinkler info chain.
    $query->leftJoin('property_sprinkler_info__field_property', 'psip', 'psip.field_property_target_id = wop.field_property_target_id AND psip.deleted = 0');
    $query->leftJoin('property_sprinkler_info__field_systems', 'psis', 'psis.entity_id = psip.entity_id AND psis.deleted = 0');
    $query->leftJoin('property_sprinkler_system__field_total_zones', 'tz', 'tz.entity_id = psis.field_systems_target_id AND tz.deleted = 0');
    $query->addExpression('SUM(tz.field_total_zones_value)', 'total_zones');

    // System type.
    $query->leftJoin('property_sprinkler_system__field_system_type', 'stype', 'stype.entity_id = psis.field_systems_target_id AND stype.deleted = 0');
    $query->leftJoin('taxonomy_term_field_data', 'stterm', 'stterm.tid = stype.field_system_type_target_id');
    $query->addField('stterm', 'name', 'system_type');

    // Work todo.
    $query->leftJoin('work_order__field_work_todo_description', 'wtd', 'wtd.entity_id = w.id AND wtd.deleted = 0');
    $query->addField('wtd', 'field_work_todo_description_value', 'work_todo');

    // Gate code + call ahead.
    $query->leftJoin('properties__field_gate_code', 'gate', 'gate.entity_id = wop.field_property_target_id AND gate.deleted = 0');
    $query->addField('gate', 'field_gate_code_value', 'gate_code');

    $query->leftJoin('properties__field_call_ahead', 'call', 'call.entity_id = wop.field_property_target_id AND call.deleted = 0');
    $query->addField('call', 'field_call_ahead_value', 'call_ahead');

    // Zip filter.
    if (!empty($zip)) {
      $query->condition('z.id', $zip);
    }

    // Street filter.
    if (!empty($street)) {
      $query->condition('addr.field_street_address_value', '%' . $this->database->escapeLike($street) . '%', 'LIKE');
    }

    $query->groupBy('w.id');
    $query->groupBy('w.type');
    $query->groupBy('wos.field_status_target_id');
    $query->groupBy('wop.field_property_target_id');
    $query->groupBy('nick.field_nickname_value');
    $query->groupBy('addr.field_street_address_value');
    $query->groupBy('faddr.field_full_address_value');
    $query->groupBy('z.title');
    $query->groupBy('z.id');
    $query->groupBy('citydata.title');
    $query->groupBy('stterm.name');
    $query->groupBy('wtd.field_work_todo_description_value');
    $query->groupBy('gate.field_gate_code_value');
    $query->groupBy('call.field_call_ahead_value');
    $query->groupBy('afh.field_aeration_flag_heads_value');
    $query->groupBy('wfd.created');
    $query->groupBy('schfd.field_date_value');
    $query->groupBy('schu.uid');

    if ($sort === 'fifo') {
      $query->orderBy('wfd.created', 'ASC');
      $query->orderBy('nick.field_nickname_value', 'ASC');
    }
    else {
      $query->orderBy('citydata.title', 'ASC');
      $query->orderBy('z.title', 'ASC');
      $query->orderBy('has_aeration', 'DESC');
      $query->orderBy('addr.field_street_address_value', 'ASC');
      $query->orderBy('nick.field_nickname_value', 'ASC');
    }

    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      try {
        $wo_url = Url::fromRoute('entity.work_order.canonical', ['work_order' => $row->id])->toString();
      } catch (\Exception $e) {
        $wo_url = '/';
      }

      $rows[] = [
        'wo_id'           => $row->id,
        'wo_url'          => $wo_url,
        'type'            => $row->type,
        'type_label'      => self::BUNDLES[$row->type] ?? $row->type,
        'type_code'       => self::BUNDLE_CODES[$row->type] ?? '???',
        'property_id'     => $row->property_id,
        'property_nickname' => trim($row->property_nickname ?? '') ?: 'Unknown',
        'street_address'  => trim($row->street_address ?? '') ?: '',
        'full_address'    => trim($row->full_address ?? '') ?: '',
        'zipcode'         => trim($row->zipcode ?? '') ?: 'Unknown',
        'zipcode_id'      => $row->zipcode_id,
        'total_zones'     => (int) ($row->total_zones ?? 0),
        'system_type'     => trim($row->system_type ?? '') ?: '',
        'work_todo'       => html_entity_decode(strip_tags($row->work_todo ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'gate_code'       => trim($row->gate_code ?? '') ?: '',
        'call_ahead'      => (bool) ($row->call_ahead ?? FALSE),
        'status_tid'      => (int) ($row->status_tid ?? 0),
        'has_aeration'    => (bool) ($row->has_aeration ?? FALSE),
        'city_name'       => trim($row->city_name ?? '') ?: '',
        'scheduled_date'  => $row->scheduled_ts ? (new \DateTime('@' . $row->scheduled_ts))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('M j, Y') : '',
        'scheduled_uid'   => (int) ($row->scheduled_uid ?? 0),
      ];
    }

    return $rows;
  }

  /**
   * Returns zip options for filter dropdown.
   */
  protected function getZips(): array {
    $query = $this->database->select('zipcodes_field_data', 'z');
    $query->fields('z', ['id', 'title']);
    $query->leftJoin('zipcodes__field_city', 'zfc', 'zfc.entity_id = z.id AND zfc.deleted = 0');
    $query->leftJoin('city_field_data', 'city', 'city.id = zfc.field_city_target_id');
    $query->addField('city', 'title', 'city_name');
    $query->orderBy('city.title', 'ASC');
    $query->orderBy('z.title', 'ASC');
    $zips = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $city = trim($row->city_name ?? '') ?: $row->title;
      $zips[$row->id] = $city . ' (' . $row->title . ')';
    }
    return $zips;
  }

  /**
   * Returns active teammates for technician dropdown.
   */
  protected function getTeammates(): array {
    $query = $this->database->select('users_field_data', 'u');
    $query->fields('u', ['uid']);
    $query->join('user__roles', 'ur', 'ur.entity_id = u.uid AND ur.roles_target_id = :role', [':role' => 'teammates']);
    $query->join('profile', 'tp', 'tp.uid = u.uid AND tp.type = :pt AND tp.status = 1', [':pt' => 'teammate_profile']);
    $query->leftJoin('profile__field_first_name', 'fn', 'fn.entity_id = tp.profile_id AND fn.deleted = 0');
    $query->leftJoin('profile__field_last_name', 'ln', 'ln.entity_id = tp.profile_id AND ln.deleted = 0');
    $query->addExpression("TRIM(CONCAT(COALESCE(fn.field_first_name_value,''),' ',COALESCE(ln.field_last_name_value,'')))", 'name');
    $query->condition('u.status', 1);
    $query->orderBy('ln.field_last_name_value', 'ASC');
    $query->orderBy('fn.field_first_name_value', 'ASC');

    $teammates = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $teammates[$row->uid] = trim($row->name);
    }
    return $teammates;
  }

  /**
   * Returns overall stats for the stats bar.
   */
  protected function getStats(array $bundles, string $zip): array {
    // Total matching.
    $q = $this->database->select('work_order', 'w');
    $q->condition('w.type', $bundles, 'IN');
    $q->join('work_order__field_status', 'wos', 'wos.entity_id = w.id AND wos.deleted = 0');
    $q->condition('wos.field_status_target_id', self::ACTIVE_STATUSES, 'IN');
    if (!empty($zip)) {
      $q->join('work_order__field_property', 'wop2', 'wop2.entity_id = w.id AND wop2.deleted = 0');
      $q->join('properties__field_zipcode_reference', 'pz2', 'pz2.entity_id = wop2.field_property_target_id AND pz2.deleted = 0');
      $q->condition('pz2.field_zipcode_reference_target_id', $zip);
    }
    $total = $q->countQuery()->execute()->fetchField();

    // Scheduled (have scheduling record).
    $sq = $this->database->select('work_order', 'w2');
    $sq->condition('w2.type', $bundles, 'IN');
    $sq->join('work_order__field_status', 'wos2', 'wos2.entity_id = w2.id AND wos2.deleted = 0');
    $sq->condition('wos2.field_status_target_id', self::ACTIVE_STATUSES, 'IN');
    $sq->join('scheduling__field_work_order', 'swo2', 'swo2.field_work_order_target_id = w2.id AND swo2.deleted = 0');
    if (!empty($zip)) {
      $sq->join('work_order__field_property', 'wop3', 'wop3.entity_id = w2.id AND wop3.deleted = 0');
      $sq->join('properties__field_zipcode_reference', 'pz3', 'pz3.entity_id = wop3.field_property_target_id AND pz3.deleted = 0');
      $sq->condition('pz3.field_zipcode_reference_target_id', $zip);
    }
    $scheduled = $sq->countQuery()->execute()->fetchField();

    return [
      'total'        => (int) $total,
      'scheduled'    => (int) $scheduled,
      'unscheduled'  => (int) $total - (int) $scheduled,
    ];
  }

}
