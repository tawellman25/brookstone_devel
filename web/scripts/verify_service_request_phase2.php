<?php

/**
 * Phase 2 verifier for bos_service_request — the new scenarios only (P0.1/P0.2/
 * P0.3/P0.5/P1.2/P1.3/P1.4). Idempotent + read-only (safe on live): it never
 * writes an entity. Complements the Gate 2/3/4 verifiers.
 *
 *   drush php:script web/scripts/verify_service_request_phase2.php
 */

use Drupal\Core\Session\AnonymousUserSession;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$etm = \Drupal::entityTypeManager();
$elig = \Drupal::service('bos_service_request.eligibility');
$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond, string $detail = '') use (&$pass, &$fail) {
  printf("  [%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $label, $detail ? " — $detail" : '');
  $cond ? $pass++ : $fail++;
};

// Anonymous render of /winterize (used by several checks).
$switcher = \Drupal::service('account_switcher');
$kernel = \Drupal::service('http_kernel');
$switcher->switchTo(new AnonymousUserSession());
try {
  $resp = $kernel->handle(Request::create('/winterize', 'GET'), HttpKernelInterface::SUB_REQUEST, TRUE);
  $html = $resp->getContent();
  $status = $resp->getStatusCode();
}
catch (\Throwable $e) {
  $html = ''; $status = 0;
}
$switcher->switchBack();

echo "== P0.1 campaign allowlist + normalization ==\n";
$campaigns = \Drupal::config('bos_service_request.settings')->get('campaigns') ?? [];
$ok('allowlist has pc26a, pc26b, legacy pc26, website', !array_diff(['website', 'pc26a', 'pc26b', 'pc26'], $campaigns), implode(',', $campaigns));

echo "== P0.2 contract 1127 removed from covered; still-covered unchanged ==\n";
$covered = (new ReflectionClass($elig))->getConstant('COVERED_CONTRACT_STATUS_TIDS');
$ok('1127 not in COVERED_CONTRACT_STATUS_TIDS', !in_array(1127, $covered, TRUE), '[' . implode(',', $covered) . ']');
// A real covered contract (1123/1651/1124/1125) with a wanted section → already_covered.
$cIds = $etm->getStorage('contracts')->getQuery()->accessCheck(FALSE)->condition('type', 'residential')
  ->condition('field_contract_year', 2026)->condition('field_contract_status', $covered, 'IN')->range(0, 200)->execute();
$checkedCovered = FALSE;
foreach ($etm->getStorage('contracts')->loadMultiple($cIds) as $c) {
  $pid = (int) $c->get('field_property')->target_id;
  if (!$pid) { continue; }
  $s = $etm->getStorage('contract_sections')->getQuery()->accessCheck(FALSE)
    ->condition('field_contract', $c->id())->condition('field_service', 369)->condition('field_do_you_want', ['1', '4'], 'IN')->range(0, 1)->execute();
  if ($s) {
    $r = $elig->evaluate($pid, 369, 2026);
    $ok('covered-status contract → already_covered (no regression)', $r->outcome === 'already_covered', "prop $pid: {$r->outcome}");
    $checkedCovered = TRUE;
    break;
  }
}
if (!$checkedCovered) { $ok('covered-status contract case found', FALSE, 'none on this env'); }

echo "== P1.4 standing flag → eligible + standing_flag_no_contract ==\n";
$ii = $etm->getStorage('property_sprinkler_info')->getQuery()->accessCheck(FALSE)->condition('field_ss_shut_down_contract', 1)->range(0, 50)->execute();
$checkedStanding = FALSE;
foreach ($etm->getStorage('property_sprinkler_info')->loadMultiple($ii) as $info) {
  $pid = (int) $info->get('field_property')->target_id;
  if (!$pid) { continue; }
  $r = $elig->evaluate($pid, 369, 2026);
  if ($r->outcome === 'eligible') {
    $ok('standing-flag-only property → eligible + standing_flag_no_contract', in_array('standing_flag_no_contract', $r->flags, TRUE), "prop $pid: [" . implode(',', $r->flags) . ']');
    $checkedStanding = TRUE;
    break;
  }
}
if (!$checkedStanding) { $ok('standing-flag property resolving eligible found', FALSE, 'all covered on this env — skipped'); }

echo "== P1.3 supply mismatch (reflection; read-only) ==\n";
$srcIds = $etm->getStorage('property_ss_sources')->getQuery()->accessCheck(FALSE)->exists('field_property_ss_system')->range(0, 50)->execute();
$checkedSupply = FALSE;
$form = \Drupal::classResolver()->getInstanceFromDefinition('Drupal\bos_service_request\Form\WinterizeForm');
$m = new ReflectionMethod($form, 'waterSupplyMismatch');
$m->setAccessible(TRUE);
foreach ($etm->getStorage('property_ss_sources')->loadMultiple($srcIds) as $s) {
  $sysId = $s->get('field_property_ss_system')->target_id;
  if (!$sysId) { continue; }
  $infoIds = $etm->getStorage('property_sprinkler_info')->getQuery()->accessCheck(FALSE)->condition('field_systems', $sysId)->range(0, 1)->execute();
  if (!$infoIds) { continue; }
  $pid = (int) $etm->getStorage('property_sprinkler_info')->load(reset($infoIds))->get('field_property')->target_id;
  if (!$pid) { continue; }
  $map = ['domestic_source' => 'city', 'dirty_water_source' => 'ditch', 'well_water_source' => 'well'];
  $match = $map[$s->bundle()] ?? 'city';
  $other = $match === 'well' ? 'city' : 'well';
  $ok('matching supply → no mismatch; unsure → no mismatch; other → mismatch',
    !$m->invoke($form, $pid, $match) && !$m->invoke($form, $pid, 'unsure') && $m->invoke($form, $pid, $other),
    "prop $pid ({$s->bundle()})");
  $checkedSupply = TRUE;
  break;
}
if (!$checkedSupply) { $ok('property with ss_sources found', FALSE, 'none reachable on this env — skipped'); }

echo "== P0.3/P0.5 landing page (anonymous) ==\n";
$ok('/winterize → 200', $status === 200);
$ok('public content renders (hero subhead + body accordions, not crew)', str_contains($html, 'splits pipe') && str_contains($html, 'winterize-detail-section'));
$ok('water-supply select + both opt-ins present', str_contains($html, 'water_supply') && str_contains($html, 'wants_recurring') && str_contains($html, 'wants_startup'));
$ok('freeze disclaimer by the submit button', str_contains($html, 'winterize-disclaimer'));
$ok('JS-free <details> accordions (>=5)', substr_count($html, '<details') >= 5, substr_count($html, '<details') . ' found');
$ok('"What happens next" present', str_contains($html, 'What happens next'));

echo "== §6.0 + owner rules ==\n";
// Crew body must never appear on the public page.
$term = $etm->getStorage('taxonomy_term')->load(369);
$crew = $term ? trim(strip_tags((string) $term->get('field_service_crew_desc')->value)) : '';
$ok('crew training body NOT on the public page', $crew === '' || !str_contains($html, substr($crew, 0, 30)));
$ok('no backflow-SERVICE link (word "backflow" as a component is allowed)', !str_contains($html, '/services/backflow'));
// No property-shaped element (invariant).
$ok('no property/candidate element in the form', !preg_match('/name="(property_id|field_property)"/', $html));

echo "== P0.4 seed idempotency (no clobber without --force) ==\n";
ob_start();
include __DIR__ . '/seed_winterizing_service_copy.php';
$seedOut = ob_get_clean();
$ok('seed without --force skips non-empty fields (no clobber)', str_contains($seedOut, 'SKIPPED'));

echo "== P3.1 no internal navigation on the public page ==\n";
$navLeak = FALSE;
foreach (['href="/admin', 'href="/teammates', 'href="/node', '>Services<', '>About<', 'block-olivero-main-menu', 'primary-nav'] as $n) {
  if (str_contains($html, $n)) { $navLeak = TRUE; }
}
$ok('no internal menu label/path renders for anonymous', !$navLeak);
$ok('dedicated marketing template applied (bo-winterize)', str_contains($html, 'bo-winterize'));
$ok('no <nav> element on the page', substr_count($html, '<nav') === 0, substr_count($html, '<nav') . ' found');

echo "== P3.2 marketing chrome + accordions ==\n";
$ok('logo (top + footer) + hero photo present', str_contains($html, 'bo-topbar__logo') && str_contains($html, 'bo-footer__logo') && str_contains($html, 'bo-hero__photo'));
$ok('brand token + page CSS attached', str_contains($html, 'bo-tokens.css') && str_contains($html, 'bo-winterize.css'));
$ok('orange submit class present', str_contains($html, 'winterize-submit'));
$ok('JS-free accordions styled container', str_contains($html, 'winterize-detail-section'));
// Every <img> must carry a non-empty alt (P3 verification #8).
preg_match_all('/<img\b[^>]*>/i', $html, $imgs);
$badAlt = 0;
foreach ($imgs[0] as $img) {
  if (!preg_match('/\balt\s*=\s*"[^"]+"/i', $img)) { $badAlt++; }
}
$ok('every <img> has a non-empty alt', $badAlt === 0, $badAlt ? "$badAlt missing" : count($imgs[0]) . ' checked');

echo "== P3.3 three independent checkboxes + labels ==\n";
$ok('all three opt-in checkboxes render', str_contains($html, 'wants_recurring') && str_contains($html, 'wants_startup') && str_contains($html, 'wants_specific_date'));
$ok('specific-date block + fee note', str_contains($html, 'winterize-specific-date') && str_contains($html, 'Additional fees may apply'));
$ok('"Last name" label + "Get on the list" submit', str_contains($html, 'Last name') && str_contains($html, 'Get on the list'));
$ok('field_wants_specific_date exists', (bool) \Drupal\field\Entity\FieldConfig::loadByName('service_request', 'sprinkler_winterizing', 'field_wants_specific_date'));

echo "== Check-your-week page (/winterize/week, pc26a) ==\n";
$switcher->switchTo(new AnonymousUserSession());
try {
  $cw = $kernel->handle(Request::create('/winterize/week', 'GET'), HttpKernelInterface::SUB_REQUEST, TRUE);
  $cwHtml = $cw->getContent();
  $cwStatus = $cw->getStatusCode();
}
catch (\Throwable $e) {
  $cwHtml = ''; $cwStatus = 0;
}
$switcher->switchBack();
$ok('/winterize/week → 200', $cwStatus === 200);
$ok('check-week form renders (marketing chrome)', str_contains($cwHtml, 'Check my week') && str_contains($cwHtml, 'bo-winterize'));
$ok('no property-shaped element on the check page (§6.0)', !preg_match('/name="(property_id|field_property)"/', $cwHtml));
$ok('no schedule week rendered on GET (only on corroborated submit)', !preg_match('/week of/i', $cwHtml));

printf("\n== RESULT: %d passed, %d failed ==\n", $pass, $fail);
