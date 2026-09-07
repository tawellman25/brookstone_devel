<?php

declare(strict_types=1);

namespace Drupal\bos_hoa;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * HOA GPS membership: point-in-polygon auto-flagging of member homes.
 *
 * A residential home (properties.property) whose field_geofield point falls
 * inside a DISCOUNTED HOA's field_boundary polygon is auto-linked (field_hoa) and
 * flagged (field_hoa_contracted = TRUE) so it gets the winterizing HOA discount.
 * Homes the scan previously linked to an HOA that no longer contains them (or an
 * HOA that is no longer discounted) are unlinked and un-flagged. Homes with NO
 * field_hoa are manual — the scan never touches their flag.
 */
final class HoaMembershipService {

  public function __construct(
    private readonly Connection $db,
    private readonly EntityTypeManagerInterface $etm,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Parse the outer ring of a POLYGON / MULTIPOLYGON WKT into [[lon,lat],...].
   */
  public function outerRing(string $wkt): array {
    if (!preg_match('/\(\(\s*(.+?)\s*\)/s', $wkt, $m)) {
      return [];
    }
    $ring = [];
    foreach (explode(',', $m[1]) as $pair) {
      $pair = trim($pair);
      if ($pair === '') { continue; }
      $xy = preg_split('/\s+/', $pair);
      if (count($xy) < 2) { continue; }
      $ring[] = [(float) $xy[0], (float) $xy[1]]; // WKT is lon lat
    }
    return $ring;
  }

  /**
   * Ray-casting point-in-polygon. $ring = [[lon,lat],...].
   */
  public function pointInRing(float $lon, float $lat, array $ring): bool {
    $inside = FALSE;
    $n = count($ring);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
      [$xi, $yi] = $ring[$i];
      [$xj, $yj] = $ring[$j];
      $intersect = (($yi > $lat) !== ($yj > $lat))
        && ($lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
      if ($intersect) { $inside = !$inside; }
    }
    return $inside;
  }

  /**
   * All hoa-bundle property IDs.
   */
  public function allHoaIds(): array {
    return array_map('intval', $this->db->query(
      "SELECT id FROM {properties_field_data} WHERE type = 'hoa'"
    )->fetchCol());
  }

  /**
   * Resolve the residential homes currently inside an HOA's boundary.
   *
   * @return int[] property IDs inside the polygon (empty if no boundary).
   */
  public function homesInside(int $hoaId): array {
    $b = $this->db->query(
      "SELECT field_boundary_value v, field_boundary_left l, field_boundary_right r,
              field_boundary_top t, field_boundary_bottom b
       FROM {properties__field_boundary} WHERE entity_id = :id",
      [':id' => $hoaId]
    )->fetchAssoc();
    if (!$b || empty($b['v'])) { return []; }
    $ring = $this->outerRing($b['v']);
    if (count($ring) < 3) { return []; }

    // Bounding-box pre-filter (fast), then precise ray-cast.
    $cands = $this->db->query(
      "SELECT g.entity_id id, g.field_geofield_lat lat, g.field_geofield_lon lon
       FROM {properties__field_geofield} g
       JOIN {properties_field_data} p ON p.id = g.entity_id AND p.type = 'property'
       WHERE g.field_geofield_lat IS NOT NULL
         AND g.field_geofield_lat BETWEEN :bottom AND :top
         AND g.field_geofield_lon BETWEEN :left AND :right",
      [':bottom' => $b['b'], ':top' => $b['t'], ':left' => $b['l'], ':right' => $b['r']]
    )->fetchAll();

    $inside = [];
    foreach ($cands as $c) {
      if ($this->pointInRing((float) $c->lon, (float) $c->lat, $ring)) {
        $inside[] = (int) $c->id;
      }
    }
    return $inside;
  }

  /**
   * Whether an HOA is flagged discounted.
   */
  public function isDiscounted(int $hoaId): bool {
    return (bool) $this->db->query(
      "SELECT field_hoa_contracted_value FROM {properties__field_hoa_contracted} WHERE entity_id = :id",
      [':id' => $hoaId]
    )->fetchField();
  }

  /**
   * Homes currently auto-linked to an HOA (field_hoa = hoaId).
   *
   * @return int[]
   */
  public function currentMembers(int $hoaId): array {
    return array_map('intval', $this->db->query(
      "SELECT entity_id FROM {properties__field_hoa} WHERE field_hoa_target_id = :id",
      [':id' => $hoaId]
    )->fetchCol());
  }

  /**
   * Sync one HOA: link + flag homes inside a discounted boundary; unlink +
   * un-flag auto-members that no longer qualify. Returns a summary array.
   */
  public function syncHoa(int $hoaId, bool $apply): array {
    $discounted = $this->isDiscounted($hoaId);
    $inside = $discounted ? $this->homesInside($hoaId) : [];
    $current = $this->currentMembers($hoaId);

    $insideSet = array_flip($inside);
    $currentSet = array_flip($current);
    $toAdd = array_values(array_filter($inside, fn($id) => !isset($currentSet[$id])));
    $toRemove = array_values(array_filter($current, fn($id) => !isset($insideSet[$id])));

    $hoaName = (string) ($this->db->query(
      "SELECT field_nickname_value FROM {properties__field_nickname} WHERE entity_id = :id",
      [':id' => $hoaId]
    )->fetchField() ?: '');

    if ($apply) {
      $storage = $this->etm->getStorage('properties');
      foreach ($toAdd as $pid) {
        $p = $storage->load($pid);
        if (!$p) { continue; }
        $p->set('field_hoa', $hoaId);
        $p->set('field_hoa_contracted', TRUE);
        if ($p->hasField('field_neighborhood') && $p->get('field_neighborhood')->isEmpty() && $hoaName !== '') {
          $p->set('field_neighborhood', $hoaName);
        }
        $p->_bos_hoa_scan = TRUE;
        $p->save();
      }
      foreach ($toRemove as $pid) {
        $p = $storage->load($pid);
        if (!$p) { continue; }
        $p->set('field_hoa', NULL);
        // Only clear the flag we auto-set (these are field_hoa-linked = auto).
        $p->set('field_hoa_contracted', FALSE);
        $p->_bos_hoa_scan = TRUE;
        $p->save();
      }
    }

    return [
      'hoa' => $hoaId, 'name' => $hoaName, 'discounted' => $discounted,
      'inside' => count($inside), 'added' => count($toAdd), 'removed' => count($toRemove),
      'add_ids' => $toAdd, 'remove_ids' => $toRemove,
    ];
  }

  /**
   * Sync every HOA. Returns per-HOA summaries.
   */
  public function syncAll(bool $apply): array {
    $out = [];
    foreach ($this->allHoaIds() as $hoaId) {
      $out[] = $this->syncHoa($hoaId, $apply);
    }
    return $out;
  }

  /**
   * Request-cached rings of all DISCOUNTED HOAs with a boundary.
   *
   * @return array [hoaId => ['ring'=>[[lon,lat]...], 'name'=>str, bbox l/r/t/b]]
   */
  private ?array $ringCache = NULL;

  public function discountedRings(): array {
    if ($this->ringCache !== NULL) { return $this->ringCache; }
    $this->ringCache = [];
    $ids = $this->db->query(
      "SELECT hc.entity_id FROM {properties__field_hoa_contracted} hc
       JOIN {properties_field_data} p ON p.id = hc.entity_id AND p.type = 'hoa'
       WHERE hc.field_hoa_contracted_value = 1"
    )->fetchCol();
    foreach ($ids as $hid) {
      $b = $this->db->query(
        "SELECT b.field_boundary_value v, b.field_boundary_left l, b.field_boundary_right r,
                b.field_boundary_top t, b.field_boundary_bottom bo,
                (SELECT field_nickname_value FROM {properties__field_nickname} WHERE entity_id = :id) nm
         FROM {properties__field_boundary} b WHERE b.entity_id = :id",
        [':id' => (int) $hid]
      )->fetchAssoc();
      if (!$b || empty($b['v'])) { continue; }
      $ring = $this->outerRing($b['v']);
      if (count($ring) < 3) { continue; }
      $this->ringCache[(int) $hid] = [
        'ring' => $ring, 'name' => (string) ($b['nm'] ?? ''),
        'l' => (float) $b['l'], 'r' => (float) $b['r'], 't' => (float) $b['t'], 'b' => (float) $b['bo'],
      ];
    }
    return $this->ringCache;
  }

  /**
   * First discounted HOA whose boundary contains the point, or NULL.
   *
   * @return array|null ['id'=>int, 'name'=>string]
   */
  public function hoaForPoint(float $lon, float $lat): ?array {
    foreach ($this->discountedRings() as $hid => $d) {
      if ($lat < $d['b'] || $lat > $d['t'] || $lon < $d['l'] || $lon > $d['r']) { continue; }
      if ($this->pointInRing($lon, $lat, $d['ring'])) {
        return ['id' => $hid, 'name' => $d['name']];
      }
    }
    return NULL;
  }

  public function logger() {
    return $this->loggerFactory->get('bos_hoa');
  }

}
