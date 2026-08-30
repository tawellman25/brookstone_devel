<?php

declare(strict_types=1);

namespace Drupal\bos_scheduling\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Map-backed route editor on the scheduling day view (STAGE 2: read-only).
 *
 * Shows a date range (default: the week containing the selected day) of
 * scheduled sprinkler WOs on one Google map, colored by day, with one route
 * line per (day, tech) drawn in field_scheduled_oder sequence. No editing yet.
 *
 * tz-safe: field_date is a smartdate Unix timestamp; we compare raw integers
 * and format in PHP (America/Denver) — never FROM_UNIXTIME (the new VPS runs
 * MariaDB in UTC; see drupal_bos_gotchas.md).
 */
final class RouteEditorController extends ControllerBase {

  /**
   * Work-order bundles this build routes now. WO-type agnostic by design —
   * add bundles here (or make it config) to extend beyond sprinkler.
   */
  private const ROUTED_BUNDLES = [
    'sprinkler_winterizing', 'sprinkler_start_up', 'sprinkler_check_up',
    'sprinkler_repair', 'sprinkler_installation', 'backflow_testing',
  ];

  /**
   * Sane bounding box for western Colorado. Coordinates outside → "No location"
   * bucket, never plotted (a bad point silently drags the viewport to the sea).
   */
  private const BBOX = ['lat_min' => 36.5, 'lat_max' => 41.5, 'lng_min' => -109.5, 'lng_max' => -105.0];

  public function __construct(protected Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * The editor shell: map container, legend, day columns, no-location bucket.
   */
  public function page(Request $request): array {
    $tz = new \DateTimeZone(date_default_timezone_get());
    [$start, $end, $days] = $this->resolveRange($request, $tz);
    $n = count($days);
    $range = (string) $n;

    // Prev/Next shift the window by its own length, anchored on the start day.
    $prev = (clone $start)->modify("-{$n} days")->format('Y-m-d');
    $next = (clone $start)->modify("+{$n} days")->format('Y-m-d');

    $gmap_key = (string) $this->config('geofield_map.settings')->get('gmap_api_key');

    return [
      '#theme' => 'bos_scheduling_route_editor',
      '#start' => $start->format('Y-m-d'),
      '#end'   => $end->format('Y-m-d'),
      '#range' => $range,
      '#prev'  => $prev,
      '#next'  => $next,
      '#has_map_key' => $gmap_key !== '',
      '#attached' => [
        'library' => ['bos_scheduling/route_editor'],
        'drupalSettings' => [
          'bosRouteEditor' => [
            'dataUrl'  => Url::fromRoute('bos_scheduling.route_editor_data')->toString(),
            'start'    => $start->format('Y-m-d'),
            'end'      => $end->format('Y-m-d'),
            'range'    => $range,
            'gmapKey'  => $gmap_key,
          ],
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * JSON: stops (with coords), no-location bucket, techs, origin, day list.
   */
  public function data(Request $request): CacheableJsonResponse {
    $tz = new \DateTimeZone(date_default_timezone_get());
    [$start, $end, $days] = $this->resolveRange($request, $tz);
    $start_ts = (clone $start)->setTime(0, 0, 0)->getTimestamp();
    $end_ts   = (clone $end)->setTime(23, 59, 59)->getTimestamp();

    $rows = $this->fetch($start_ts, $end_ts);

    $stops = [];
    $no_location = [];
    $techs = [];
    foreach ($rows as $r) {
      $day = (new \DateTime('@' . (int) $r->date_ts))->setTimezone($tz)->format('Y-m-d');
      $uid = (int) ($r->assigned_uid ?? 0);
      $tech = trim((string) ($r->teammate_name ?? '')) ?: ($uid ? 'Unknown' : 'Unassigned');
      if ($uid && !isset($techs[$uid])) {
        $techs[$uid] = ['uid' => $uid, 'name' => $tech];
      }
      try {
        $wo_url = Url::fromRoute('entity.work_order.canonical', ['work_order' => $r->wo_id])->toString();
      }
      catch (\Throwable $e) {
        $wo_url = '/';
      }
      $base = [
        'scheduling_id' => (int) $r->id,
        'wo_id' => (int) $r->wo_id,
        'wo_url' => $wo_url,
        'date' => $day,
        'assigned_uid' => $uid,
        'tech' => $tech,
        'order' => (int) ($r->schedule_order ?? 0),
        'nickname' => mb_substr(trim((string) ($r->property_nickname ?? '')) ?: 'Unknown', 0, 120),
        'service_code' => strtoupper(trim((string) ($r->service_code ?? ''))) ?: '?',
        'status_tid' => (int) ($r->status_tid ?? 0),
        'status_label' => DispatchController::STATUS_LABELS[(int) ($r->status_tid ?? 0)] ?? 'Unknown',
      ];
      $coord = $this->parsePoint((string) ($r->geofield ?? ''));
      if ($coord === NULL) {
        $no_location[] = $base + ['reason' => trim((string) ($r->geofield ?? '')) === '' ? 'missing' : 'out_of_bounds'];
      }
      else {
        $stops[] = $base + ['lat' => $coord['lat'], 'lng' => $coord['lng']];
      }
    }

    // Route origin (the shop) from config → property → geofield. Fail loud.
    $origin = $this->originPoint();

    $payload = [
      'range' => [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'days' => array_map(fn($d) => $d->format('Y-m-d'), $days),
      ],
      'origin' => $origin,
      'techs' => array_values($techs),
      'stops' => $stops,
      'no_location' => $no_location,
      'counts' => ['stops' => count($stops), 'no_location' => count($no_location)],
    ];

    $response = new CacheableJsonResponse($payload);
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
    return $response;
  }

  /**
   * Range query — reuses the dispatch join pattern, generalized to a date
   * window + geofield + routed bundles. Ordered deterministically.
   */
  protected function fetch(int $start_ts, int $end_ts): array {
    $q = $this->database->select('scheduling_field_data', 's');
    $q->fields('s', ['id']);
    $q->join('scheduling__field_date', 'fd', 's.id = fd.entity_id AND fd.deleted = 0');
    $q->addField('fd', 'field_date_value', 'date_ts');
    $q->condition('fd.field_date_value', $start_ts, '>=');
    $q->condition('fd.field_date_value', $end_ts, '<=');

    $q->join('scheduling__field_work_order', 'swo', 's.id = swo.entity_id AND swo.deleted = 0');
    $q->addField('swo', 'field_work_order_target_id', 'wo_id');

    // Routed bundles only (WO-type agnostic via the constant).
    $q->join('work_order_field_data', 'wo', 'wo.id = swo.field_work_order_target_id');
    $q->condition('wo.type', self::ROUTED_BUNDLES, 'IN');

    $q->leftJoin('work_order__field_status', 'wos', 'wos.entity_id = swo.field_work_order_target_id AND wos.deleted = 0');
    $q->condition('wos.field_status_target_id', DispatchController::VISIBLE_STATUSES, 'IN');
    $q->addField('wos', 'field_status_target_id', 'status_tid');

    $q->leftJoin('scheduling__field_assigned_to', 'sat', 's.id = sat.entity_id AND sat.deleted = 0');
    $q->addField('sat', 'field_assigned_to_target_id', 'assigned_uid');
    $q->leftJoin('scheduling__field_scheduled_oder', 'sord', 's.id = sord.entity_id AND sord.deleted = 0');
    $q->addField('sord', 'field_scheduled_oder_value', 'schedule_order');

    $q->leftJoin('work_order__field_property', 'wop', 'wop.entity_id = swo.field_work_order_target_id AND wop.deleted = 0');
    $q->leftJoin('properties__field_nickname', 'nick', 'nick.entity_id = wop.field_property_target_id AND nick.deleted = 0');
    $q->addField('nick', 'field_nickname_value', 'property_nickname');
    $q->leftJoin('properties__field_geofield', 'geo', 'geo.entity_id = wop.field_property_target_id AND geo.deleted = 0');
    $q->addField('geo', 'field_geofield_value', 'geofield');

    $q->leftJoin('work_order__field_service', 'wosvc', 'wosvc.entity_id = swo.field_work_order_target_id AND wosvc.deleted = 0');
    $q->leftJoin('taxonomy_term__field_sop_code', 'svccode', 'svccode.entity_id = wosvc.field_service_target_id AND svccode.deleted = 0');
    $q->addField('svccode', 'field_sop_code_value', 'service_code');

    $q->leftJoin('profile', 'tp', 'tp.uid = sat.field_assigned_to_target_id AND tp.type = :pt AND tp.status = 1', [':pt' => 'teammate_profile']);
    $q->leftJoin('profile__field_first_name', 'pfn', 'pfn.entity_id = tp.profile_id AND pfn.deleted = 0');
    $q->leftJoin('profile__field_last_name', 'pln', 'pln.entity_id = tp.profile_id AND pln.deleted = 0');
    $q->addExpression("TRIM(CONCAT(COALESCE(pfn.field_first_name_value,''),' ',COALESCE(pln.field_last_name_value,'')))", 'teammate_name');

    // Deterministic ordering: date, tech, then sequence (never range without sort).
    $q->orderBy('fd.field_date_value', 'ASC');
    $q->orderBy('pln.field_last_name_value', 'ASC');
    $q->orderBy('sord.field_scheduled_oder_value', 'ASC');

    return $q->execute()->fetchAll();
  }

  /**
   * Resolve [start, end, days[]] from ?date + ?range (1|3|7). Default: the
   * Sunday-anchored week containing the selected/target day.
   */
  protected function resolveRange(Request $request, \DateTimeZone $tz): array {
    $date_param = (string) $request->query->get('date', '');
    $sel = ($date_param && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_param))
      ? new \DateTime($date_param, $tz) : new \DateTime('today', $tz);
    $sel->setTime(0, 0, 0);
    $range = (int) $request->query->get('range', 7);
    $range = in_array($range, [1, 3, 7], TRUE) ? $range : 7;

    if ($range === 7) {
      $start = (clone $sel)->modify('-' . (int) $sel->format('w') . ' days');
    }
    else {
      $start = clone $sel;
    }
    $days = [];
    for ($i = 0; $i < $range; $i++) {
      $days[] = (clone $start)->modify("+$i days");
    }
    $end = end($days);
    return [$start, $end, $days];
  }

  /**
   * Parse a "POINT (lng lat)" WKT to ['lat','lng'], or NULL if missing/invalid/
   * outside the western-CO bounding box.
   */
  protected function parsePoint(string $wkt): ?array {
    if ($wkt === '' || !preg_match('/POINT\s*\(\s*(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s*\)/i', $wkt, $m)) {
      return NULL;
    }
    $lng = (float) $m[1];
    $lat = (float) $m[2];
    if ($lat < self::BBOX['lat_min'] || $lat > self::BBOX['lat_max']
      || $lng < self::BBOX['lng_min'] || $lng > self::BBOX['lng_max']) {
      return NULL;
    }
    return ['lat' => $lat, 'lng' => $lng];
  }

  /**
   * The route origin (shop): config route_origin_property_id → property →
   * geofield. Returns NULL (with a reason) if unusable, so the UI can disable
   * Optimize rather than route from (0,0).
   */
  protected function originPoint(): ?array {
    $pid = (int) ($this->config('bos_scheduling.settings')->get('route_origin_property_id') ?: 50413);
    $p = $this->entityTypeManager()->getStorage('properties')->load($pid);
    if (!$p) {
      return ['ok' => FALSE, 'reason' => "origin property $pid not found", 'pid' => $pid];
    }
    $coord = $this->parsePoint((string) $p->get('field_geofield')->value);
    if ($coord === NULL) {
      return ['ok' => FALSE, 'reason' => "origin property $pid has no usable geofield", 'pid' => $pid];
    }
    return ['ok' => TRUE, 'pid' => $pid, 'label' => $p->label(), 'lat' => $coord['lat'], 'lng' => $coord['lng']];
  }

}
