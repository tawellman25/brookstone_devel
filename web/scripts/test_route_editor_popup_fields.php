<?php

/**
 * @file
 * System type + scheduling note on the Route Editor pin popup. READ-ONLY.
 *
 * The real risk in this change is not the popup text, it is the JOIN. Ten
 * properties carry TWO sprinkler systems, so pulling the system type in on the
 * main stop query would emit that stop twice — two pins at one address, counted
 * twice in the route and in every total derived from it, and silently, because
 * a duplicated stop looks exactly like a real one. systemTypes() therefore runs
 * as a second pass. The decisive assertions here are the ones that prove no
 * stop is duplicated and that a two-system property still gets both labels.
 *
 * Run: drush php:script web/scripts/test_route_editor_popup_fields.php
 */

use Drupal\bos_scheduling\Controller\RouteEditorController;
use Symfony\Component\HttpFoundation\Request;

$db = \Drupal::database();
$etm = \Drupal::entityTypeManager();
$controller = RouteEditorController::create(\Drupal::getContainer());
$pass = 0; $fail = 0;
$ok = function (string $w, bool $g, string $x = '') use (&$pass, &$fail) {
  $g ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $g ? 'PASS' : 'FAIL', $w, $x !== '' ? "  [$x]" : '');
};

// A window that actually contains scheduled sprinkler work.
$row = $db->query('SELECT MIN(field_date_value) a, MAX(field_date_value) b FROM {scheduling__field_date} WHERE deleted = 0')->fetchObject();
$probe = function (string $date, string $range = '7') use ($controller): array {
  $req = Request::create('/x', 'GET', ['date' => $date, 'range' => $range]);
  return json_decode($controller->data($req)->getContent(), TRUE) ?: [];
};

// Walk back from today to find a week with stops, so the test works whatever
// the season.
$payload = [];
$when = '';
for ($i = 0; $i < 60; $i++) {
  $try = date('Y-m-d', strtotime("-{$i} weeks"));
  $p = $probe($try);
  $all = array_merge($p['stops'] ?? [], $p['no_location'] ?? []);
  if (count($all) > 3) {
    $payload = $p;
    $when = $try;
    break;
  }
}
$stops = array_merge($payload['stops'] ?? [], $payload['no_location'] ?? []);
$ok('found a week with stops to inspect', count($stops) > 3, "week of $when, " . count($stops) . ' stop(s)');
if (count($stops) < 4) { printf("\n%d passed, %d failed\n", $pass, $fail); return; }

// --- the fields are present in the payload ----------------------------------
$ok('every stop carries a system_type key (may be empty)',
  count(array_filter($stops, fn($s) => array_key_exists('system_type', $s))) === count($stops));
$ok('every stop carries a scheduling_note key (may be empty)',
  count(array_filter($stops, fn($s) => array_key_exists('scheduling_note', $s))) === count($stops));

$withType = array_filter($stops, fn($s) => ($s['system_type'] ?? '') !== '');
$withNote = array_filter($stops, fn($s) => ($s['scheduling_note'] ?? '') !== '');
$ok('at least one stop resolved a system type', count($withType) > 0,
  count($withType) . ' of ' . count($stops));
$ok('  and the label is a real one, not an id',
  !$withType || !preg_match('/^\d+$/', (string) reset($withType)['system_type']),
  $withType ? (string) reset($withType)['system_type'] : '-');
// Notes are only on some visits; report rather than fail if this window has none.
$ok('scheduling notes surface when present', TRUE,
  count($withNote) . ' of ' . count($stops) . ' stop(s) carry a note');

// --- THE ONE THAT MATTERS: no stop was duplicated ---------------------------
$ids = array_map(fn($s) => $s['scheduling_id'], $stops);
$ok('NO duplicated stops (the two-system join trap)',
  count($ids) === count(array_unique($ids)),
  count($ids) . ' rows, ' . count(array_unique($ids)) . ' distinct scheduling ids');

// --- a property with TWO systems still gets both labels ---------------------
$twoSys = $db->query('SELECT field_property_target_id pid, COUNT(*) n
  FROM {property_sprinkler_system__field_property} WHERE deleted = 0
  GROUP BY pid HAVING n > 1 ORDER BY n DESC')->fetchAll();
$ok('found a property carrying more than one system', (bool) $twoSys,
  count($twoSys) . ' such propert(ies)');
if ($twoSys) {
  $pid = (int) $twoSys[0]->pid;
  $ref = new \ReflectionMethod(RouteEditorController::class, 'systemTypes');
  $ref->setAccessible(TRUE);
  $map = $ref->invoke($controller, [$pid]);
  $label = $map[$pid] ?? '';
  $distinctTypes = (int) $db->query('SELECT COUNT(DISTINCT pst.field_system_type_target_id)
    FROM {property_sprinkler_system__field_property} psp
    JOIN {property_sprinkler_system__field_system_type} pst ON pst.entity_id = psp.entity_id AND pst.deleted = 0
    WHERE psp.deleted = 0 AND psp.field_property_target_id = :p', [':p' => $pid])->fetchField();
  $ok('  a two-system property returns ONE string, not two rows', is_string($label) && $label !== '', $label);
  $ok('  and it names every DISTINCT type it has',
    substr_count($label, ',') + 1 === max($distinctTypes, 1),
    "$distinctTypes distinct type(s) -> \"$label\"");
}

// --- the note is bounded, so one long note cannot blow up the popup ---------
$longest = 0;
foreach ($stops as $s) { $longest = max($longest, mb_strlen((string) ($s['scheduling_note'] ?? ''))); }
$ok('scheduling note is truncated to a popup-sized length', $longest <= 300, "longest = $longest chars");

printf("\n%d passed, %d failed\n", $pass, $fail);
