<?php
/**
 * The canonical tag must name https://brookstoneoutdoors.com, whatever host
 * was asked for.
 *
 * The www redirect in .htaccess means no www request should reach PHP at all,
 * so this is the backstop for one that somehow does -- a request by IP with a
 * Host header, or an .htaccess that went missing. It is also the half of the
 * fix that CAN be tested here: DDEV runs nginx-fpm, which does not read
 * .htaccess, so the redirect itself is only provable against production.
 */
$pass = 0; $fail = 0;
$ok = function (string $what, bool $good, string $got = '') use (&$pass, &$fail) {
  $good ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $good ? 'PASS' : 'FAIL', $what, $got !== '' ? " [$got]" : '');
};

$make = fn(array $tags) => ['#attached' => ['html_head' => array_map(
  fn($k, $v) => [['#tag' => str_starts_with($k, 'canonical') || $k === 'shortlink' ? 'link' : 'meta',
    '#attributes' => (str_starts_with($k, 'canonical') || $k === 'shortlink') ? ['href' => $v] : ['content' => $v]], $k],
  array_keys($tags), $tags)]];

$read = function (array $a, string $key) {
  foreach ($a['#attached']['html_head'] as $item) {
    if (($item[1] ?? '') === $key) {
      return $item[0]['#attributes']['href'] ?? $item[0]['#attributes']['content'] ?? NULL;
    }
  }
  return NULL;
};

print "1. a www request's tags are pinned to the canonical host\n";
$a = $make([
  'canonical_url' => 'https://www.brookstoneoutdoors.com/contact',
  'og_url' => 'https://www.brookstoneoutdoors.com/contact',
  'shortlink' => 'https://www.brookstoneoutdoors.com/node/5',
  'og_image_0' => 'https://www.brookstoneoutdoors.com/img/a.jpg',
]);
_bos_service_request_canonicalise_hosts($a);
$ok('canonical loses the www', $read($a, 'canonical_url') === 'https://brookstoneoutdoors.com/contact', (string) $read($a, 'canonical_url'));
$ok('og:url loses the www', $read($a, 'og_url') === 'https://brookstoneoutdoors.com/contact');
$ok('shortlink loses the www', $read($a, 'shortlink') === 'https://brookstoneoutdoors.com/node/5');
$ok('og:image loses the www (suffixed key matched)', $read($a, 'og_image_0') === 'https://brookstoneoutdoors.com/img/a.jpg', (string) $read($a, 'og_image_0'));

print "\n2. http is normalised to https\n";
$b = $make(['canonical_url' => 'http://brookstoneoutdoors.com/about-us']);
_bos_service_request_canonicalise_hosts($b);
$ok('http becomes https', $read($b, 'canonical_url') === 'https://brookstoneoutdoors.com/about-us', (string) $read($b, 'canonical_url'));

print "\n3. the query string and path survive exactly\n";
$c = $make(['canonical_url' => 'https://www.brookstoneoutdoors.com/?c=gbp26&utm_source=gbp&utm_medium=listing']);
_bos_service_request_canonicalise_hosts($c);
$ok('query string untouched', $read($c, 'canonical_url') === 'https://brookstoneoutdoors.com/?c=gbp26&utm_source=gbp&utm_medium=listing',
  (string) $read($c, 'canonical_url'));

print "\n4. nothing else is touched\n";
$d = $make([
  'canonical_url' => 'https://brookstone.ddev.site/contact',
  'description' => 'https://www.brookstoneoutdoors.com should not be rewritten in prose',
  'og_title' => 'Brookstone Outdoors',
]);
_bos_service_request_canonicalise_hosts($d);
$ok('a different host is left alone', $read($d, 'canonical_url') === 'https://brookstone.ddev.site/contact', (string) $read($d, 'canonical_url'));
$ok('non-URL tags are not rewritten', str_contains((string) $read($d, 'description'), 'www.brookstoneoutdoors.com'));
$ok('unrelated tags untouched', $read($d, 'og_title') === 'Brookstone Outdoors');

print "\n5. a mid-string hostname is not rewritten (anchored at the start)\n";
$e = $make(['canonical_url' => 'https://example.com/go?to=https://www.brookstoneoutdoors.com/x']);
_bos_service_request_canonicalise_hosts($e);
$ok('only a leading host matches', $read($e, 'canonical_url') === 'https://example.com/go?to=https://www.brookstoneoutdoors.com/x');

print "\n6. an empty or malformed set does not explode\n";
$f = ['#attached' => []];
_bos_service_request_canonicalise_hosts($f);
$ok('no html_head is survivable', TRUE);
$g = ['#attached' => ['html_head' => [[['#tag' => 'link', '#attributes' => []], 'canonical_url']]]];
_bos_service_request_canonicalise_hosts($g);
$ok('a tag with no href is survivable', TRUE);

printf("\n%d passed, %d failed\n", $pass, $fail);
