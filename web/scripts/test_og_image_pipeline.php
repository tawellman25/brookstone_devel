<?php

/**
 * @file
 * The Open Graph image pipeline, tested over REAL HTTP as a share scraper sees it.
 *
 * Why HTTP and not the metatag API: the three og:image handlers use DIFFERENT
 * hooks — bos_geo alters tag VALUES during generation (hook_metatags_alter),
 * bos_services and bos_service_request edit RENDERED ATTACHMENTS
 * (hook_metatags_attachments_alter). Only a real page render exercises all
 * three, and a scraper reads the rendered page, so that is what is asserted.
 *
 * THE PRIMARY REQUIREMENT IS THAT NOTHING ELSE MOVED. Todd's constraint on this
 * work was that it must not disturb what BOS already has in place, so every page
 * here is also checked for its existing og:title / og:description / og:url /
 * og:type still being present and non-empty. A passing og:image with a broken
 * og:title is a failed change.
 *
 * Set BOS_OG_BASE to point at another environment (default: the dev site).
 *
 * Run: drush php:script web/scripts/test_og_image_pipeline.php
 */

$base = rtrim(getenv('BOS_OG_BASE') ?: 'https://brookstone.ddev.site', '/');
$pass = 0; $fail = 0;
$ok = function (string $w, bool $g, string $x = '') use (&$pass, &$fail) {
  $g ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $g ? 'PASS' : 'FAIL', $w, $x !== '' ? "  [$x]" : '');
};

/** Fetch a page and pull out its meta tags, keyed by property/name. */
$meta = function (string $path) use ($base): array {
  $ch = curl_init($base . $path);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_FOLLOWLOCATION => TRUE,
    CURLOPT_SSL_VERIFYPEER => FALSE,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_TIMEOUT => 45,
    // Identify as a scraper would; some stacks vary by UA.
    CURLOPT_USERAGENT => 'facebookexternalhit/1.1',
  ]);
  $html = (string) curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $tags = ['_status' => $code];
  if (preg_match_all('/<meta[^>]+>/i', $html, $m)) {
    foreach ($m[0] as $tag) {
      if (!preg_match('/(?:property|name)="([^"]+)"/i', $tag, $k)) {
        continue;
      }
      if (!preg_match('/content="([^"]*)"/i', $tag, $v)) {
        continue;
      }
      // Later duplicates would mean two tags for one property — keep the first
      // and record the count so duplication is visible.
      $key = $k[1];
      if (isset($tags[$key])) {
        $tags['_dupe_' . $key] = ($tags['_dupe_' . $key] ?? 1) + 1;
        continue;
      }
      $tags[$key] = html_entity_decode($v[1], ENT_QUOTES | ENT_HTML5);
    }
  }
  return $tags;
};

// One URL per code path that sets og:image.
$cases = [
  'service term (bos_services)' => ['/services/landscaping', TRUE],
  'geo hub (bos_geo)' => ['/colorado/delta-county', TRUE],
  'winterize (bos_service_request)' => ['/winterize', FALSE],
  'front page (global default)' => ['/', FALSE],
];

foreach ($cases as $label => [$path, $expectStyle]) {
  printf("\n--- %s  %s\n", $label, $path);
  $t = $meta($path);
  $ok('page loads', $t['_status'] === 200, 'HTTP ' . $t['_status']);
  if ($t['_status'] !== 200) {
    continue;
  }

  $img = $t['og:image'] ?? '';
  $ok('og:image present', $img !== '');
  $ok('  absolute, with scheme and host',
    (bool) preg_match('#^https?://[^/]+/#', $img), substr($img, 0, 72));
  $ok('  exactly ONE og:image tag', !isset($t['_dupe_og:image']),
    isset($t['_dupe_og:image']) ? $t['_dupe_og:image'] . ' tags' : 'one');
  if ($expectStyle) {
    $ok('  served through the og_image style', str_contains($img, '/og_image/'),
      str_contains($img, 'max_1300x1300') ? 'STILL on max_1300x1300' : substr($img, -60));
    $ok('  og:image:width is 1200', ($t['og:image:width'] ?? '') === '1200', $t['og:image:width'] ?? '(absent)');
    $ok('  og:image:height is 630', ($t['og:image:height'] ?? '') === '630', $t['og:image:height'] ?? '(absent)');
  }
  else {
    // Static assets: dimensions must still be stated, just not 1200x630.
    $ok('  dimensions are stated',
      ($t['og:image:width'] ?? '') !== '' && ($t['og:image:height'] ?? '') !== '',
      ($t['og:image:width'] ?? '?') . 'x' . ($t['og:image:height'] ?? '?'));
  }
  $ok('  og:image:alt is non-empty', trim($t['og:image:alt'] ?? '') !== '',
    mb_substr($t['og:image:alt'] ?? '(absent)', 0, 50));

  // --- the constraint: nothing that already worked may have moved -----------
  foreach (['og:title', 'og:description', 'og:url', 'og:type'] as $keep) {
    $ok('  UNCHANGED: ' . $keep . ' still present', trim($t[$keep] ?? '') !== '',
      mb_substr($t[$keep] ?? '(MISSING)', 0, 46));
  }
  $ok('  og:url is absolute and on the right host',
    (bool) preg_match('#^https?://[^/]*brookstone#i', $t['og:url'] ?? ''), $t['og:url'] ?? '-');
}

// --- drift guard: both style-based handlers must name the SAME style ---------
printf("\n--- code \n");
$root = \Drupal::service('extension.list.module');
$svc = file_get_contents($root->getPath('bos_services') . '/bos_services.module');
$geo = file_get_contents($root->getPath('bos_geo') . '/bos_geo.module');
$ok('bos_services loads the og_image style', str_contains($svc, "ImageStyle::load('og_image')"));
$ok('bos_geo loads the og_image style', str_contains($geo, "ImageStyle::load('og_image')"));
$ok('neither still references max_1300x1300 for og:image',
  !str_contains($svc, "ImageStyle::load('max_1300x1300')") && !str_contains($geo, "ImageStyle::load('max_1300x1300')"));

$style = \Drupal::entityTypeManager()->getStorage('image_style')->load('og_image');
$ok('the og_image style exists', (bool) $style);
if ($style) {
  $eff = [];
  foreach ($style->getEffects() as $e) {
    $eff[] = $e->getPluginId() . ':' . json_encode($e->getConfiguration()['data'] ?? []);
  }
  $ok('  it is a scale-and-crop at 1200x630',
    (bool) preg_match('/image_scale_and_crop.*"width":1200.*"height":630/', implode(' ', $eff)),
    implode(' ', $eff));
}

printf("\n%d passed, %d failed\n", $pass, $fail);
