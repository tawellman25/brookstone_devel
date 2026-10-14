<?php

/**
 * @file
 * The Route Editor toolbar day-jump: pick a date instead of walking Prev/Next.
 *
 * Read-only — renders RouteEditorController::page() for several ?date/?range
 * combinations and inspects the HTML. Nothing is written, so this is safe on
 * live.
 *
 * What it actually guards:
 *
 *   1. The three new variables REACH THE TEMPLATE. A theme hook only receives
 *      variables declared in hook_theme(); an undeclared one silently renders
 *      as empty (the warning already written on bos_scheduling_hub). Asserting
 *      the controller's return array would pass while the page showed nothing,
 *      so every check here reads RENDERED HTML.
 *   2. The input's value is the RESOLVED window start, not the raw ?date — in
 *      Week view resolveRange() Sunday-anchors, so a Wednesday pick must come
 *      back as that Sunday or the picker and the map disagree about the week.
 *   3. The visible window label is US format, never ISO (CLAUDE.md "Date
 *      Formatting"), and a one-day window prints one date rather than twice.
 *   4. "Today" links to today and reads as satisfied only when the window
 *      actually contains today — which for a Sunday-anchored week is a range
 *      test, not start === today.
 *
 * Run: drush php:script web/scripts/test_route_editor_date_picker.php
 */

use Drupal\bos_scheduling\Controller\RouteEditorController;
use Symfony\Component\HttpFoundation\Request;

$tz = new \DateTimeZone(date_default_timezone_get());
$controller = RouteEditorController::create(\Drupal::getContainer());
$renderer = \Drupal::service('renderer');
$pass = 0; $fail = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail) {
  printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  — $detail" : '');
  $ok ? $pass++ : $fail++;
};

/**
 * Render the editor shell for one ?date + ?range as the browser would get it.
 */
$render = function (string $date, string $range) use ($controller, $renderer): string {
  $req = Request::create('/admin/office/work-orders/scheduling/route-editor', 'GET', [
    'date' => $date,
    'range' => $range,
  ]);
  $build = $controller->page($req);
  return (string) $renderer->renderRoot($build);
};

$today = (new \DateTime('today', $tz))->format('Y-m-d');

// --- 1. the control exists at all -------------------------------------------
// A Wednesday in Week view: the pick that proves the input shows the RESOLVED
// start rather than what was asked for.
$wed = '2026-10-14';
$html = $render($wed, '7');
$check('the toolbar renders a native date input',
  (bool) preg_match('/<input[^>]*class="bos-re__jump-date"[^>]*>/', $html));
preg_match('/<input[^>]*class="bos-re__jump-date"[^>]*>/', $html, $m);
$input = $m[0] ?? '';
$check('  it is type=date (so the browser supplies its own calendar)',
  str_contains($input, 'type="date"'), $input === '' ? 'no input found' : '');
$check('  it carries an aria-label', str_contains($input, 'aria-label='));

// --- 2. value = resolved window start, not the raw ?date --------------------
// 2026-10-14 is a Wednesday; the Sunday-anchored week starts 2026-10-11.
preg_match('/class="bos-re__jump-date"[^>]*value="([^"]*)"|value="([^"]*)"[^>]*class="bos-re__jump-date"/', $input, $vm);
$value = $vm[1] ?: ($vm[2] ?? '');
$check('Week view: the input reads back the week START, not the picked day',
  $value === '2026-10-11', "picked $wed → input shows " . ($value ?: '(empty)'));
$check('  and the value is ISO (what the control and ?date= both speak)',
  (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value), $value);

// Day view anchors on the day itself.
$day_html = $render($wed, '1');
preg_match('/<input[^>]*class="bos-re__jump-date"[^>]*>/', $day_html, $dm);
$check('Day view: the input shows the picked day itself',
  str_contains($dm[0] ?? '', 'value="' . $wed . '"'));

// --- 3. the VISIBLE window label is US format -------------------------------
preg_match('#<span class="bos-re__dates">(.*?)</span>#s', $html, $lm);
$label = trim($lm[1] ?? '');
$check('Week label is US format with the weekday',
  $label === 'Sun 10/11/2026 → Sat 10/17/2026', $label ?: '(empty — variable reached the template?)');
$check('  and no ISO date is printed as display text',
  !preg_match('/>[^<]*\d{4}-\d{2}-\d{2}/', preg_replace('/<input[^>]*>|<a[^>]*>/', '', $html)),
  'ISO is for the links and the input only');

preg_match('#<span class="bos-re__dates">(.*?)</span>#s', $day_html, $dlm);
$check('one-day window prints ONE date, not the same date twice',
  trim($dlm[1] ?? '') === 'Wed 10/14/2026', trim($dlm[1] ?? ''));

// --- 4. Today ---------------------------------------------------------------
$check('a Today button links to today',
  str_contains($html, 'date=' . $today) && str_contains($html, 'bos-re__today-btn'),
  "today=$today");
// The attribute spans a newline in the template and the literal '&' in the
// href is NOT entity-escaped (template text, not a variable — true of every
// toolbar link here), so match the anchor first and then read its href.
preg_match('#<a[^>]*bos-re__today-btn[^>]*>Today</a>#s', $html, $tm);
$check('  Today keeps the current range (a jump, not a mode change)',
  str_contains($tm[0] ?? '', '?range=7&date=' . $today), $tm[0] ?? '(no Today anchor)');

// A week far from now must NOT read as satisfied; the week containing today must.
$far = $render('2020-01-08', '7');
$check('  Today is NOT marked current on a window that excludes today',
  !preg_match('/bos-re__today-btn[^"]*is-current/', $far));
$now_html = $render($today, '7');
$check('  Today IS marked current on the week containing today (range test, not start ===)',
  (bool) preg_match('/bos-re__today-btn[^"]*is-current/', $now_html),
  'week is Sunday-anchored, so start === today is almost never true');

// --- 5. nothing the picker changed broke the rest of the toolbar ------------
$check('range buttons still render and mark the active one',
  substr_count($html, 'bos-re__range-btn') === 3
  && (bool) preg_match('/bos-re__range-btn is-active[^>]*>Week</', $html));
$check('Prev/Next still shift by a whole window',
  str_contains($html, 'date=2026-10-04') && str_contains($html, 'date=2026-10-18'));

printf("\n%d passed, %d failed\n", $pass, $fail);
