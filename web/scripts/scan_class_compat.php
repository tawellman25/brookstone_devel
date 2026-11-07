<?php

/**
 * @file
 * Pre-flight for a core major upgrade: which modules' classes FATAL on load
 * against the core that is now on disk?
 *
 * Why this exists (Drupal 11 project, step 6, 2026-11-07): `drush updb` on
 * core 11.4.12 died twice with PHP compile-time fatals in CONTRIB that
 * declares `^11` — smart_date 4.2.5 (normalize() return type no longer
 * compatible with core's TimestampItemNormalizer) and imagefield_tokens
 * ($currentUser property type no longer matches core's ImageFormatter).
 * `upgrade_status` cannot see these: it analyses against the core you are
 * leaving, not the one you are moving to. `core_version_requirement` is a
 * claim. These fatals are not catchable — they kill the process at class
 * load — so the only honest check is to LOAD every class, each module in its
 * own child process, with the new core on disk, and collect what dies.
 *
 * Run from the project root, INSIDE the container (no Drupal bootstrap needed;
 * it registers the same PSR-4 roots DrupalKernel would):
 *
 *   ddev exec php web/scripts/scan_class_compat.php            # contrib + custom
 *   ddev exec php web/scripts/scan_class_compat.php --all      # also core modules
 *   ddev exec php web/scripts/scan_class_compat.php --only=smart_date,imagefield_tokens
 *
 * Output: one line per module; FATAL lines carry the PHP message. Exit 1 if
 * any module fatals on an incompatibility (a parent/interface that merely
 * does not exist — an optional dependency that is not installed — is
 * reported separately and does not fail the run).
 */

$root = realpath(__DIR__ . '/../..');
$web = $root . '/web';
$args = array_slice($argv, 1);
$all = in_array('--all', $args, TRUE);
$only = NULL;
foreach ($args as $a) {
  if (str_starts_with($a, '--only=')) {
    $only = array_filter(array_map('trim', explode(',', substr($a, 7))));
  }
}

// --- discover every extension dir (module or theme) that has a src/ --------
$roots = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($web, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
  if ($f->getExtension() !== 'yml' || !str_ends_with($f->getFilename(), '.info.yml')) {
    continue;
  }
  $dir = $f->getPath();
  if (str_contains($dir, '/tests/') || str_contains($dir, '/node_modules/')) {
    continue;
  }
  $name = substr($f->getFilename(), 0, -9);
  if ($name !== basename($dir) && !is_dir("$dir/src")) {
    continue;
  }
  if (!is_dir("$dir/src")) {
    continue;
  }
  $kind = str_contains($dir, '/core/') ? 'core' : (str_contains($dir, '/contrib/') ? 'contrib' : 'custom');
  $roots[$name] = ['dir' => $dir, 'kind' => $kind];
}
ksort($roots);

// --- child mode: load every class of ONE module --------------------------
if (($args[0] ?? '') === '--child') {
  $target = $args[1];
  $map = json_decode(file_get_contents($args[2]), TRUE);
  require $root . '/vendor/autoload.php';
  $loader = new \Composer\Autoload\ClassLoader();
  foreach ($map as $n => $r) {
    $loader->addPsr4("Drupal\\$n\\", $r['dir'] . '/src');
  }
  $loader->register();
  error_reporting(E_ALL);
  ini_set('display_errors', '1');
  $dir = $map[$target]['dir'] . '/src';
  $n = 0;
  $missing = [];
  $rit = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
  foreach ($rit as $f) {
    if ($f->getExtension() !== 'php') {
      continue;
    }
    $rel = substr($f->getPathname(), strlen($dir) + 1, -4);
    $class = "Drupal\\$target\\" . str_replace('/', '\\', $rel);
    try {
      // A missing parent/interface/trait throws a catchable Error; a signature
      // or property-type mismatch is a COMPILE fatal that ends this child.
      if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
        $n++;
      }
    }
    catch (\Throwable $e) {
      $missing[] = $class . ' — ' . preg_replace('/\s+/', ' ', $e->getMessage());
    }
  }
  echo json_encode(['loaded' => $n, 'missing' => $missing]);
  exit(0);
}

// --- parent mode -----------------------------------------------------------
$mapfile = sys_get_temp_dir() . '/scan_class_compat_map.json';
file_put_contents($mapfile, json_encode($roots));
$targets = array_filter($roots, fn($r, $n) => ($all || $r['kind'] !== 'core') && (!$only || in_array($n, $only, TRUE)), ARRAY_FILTER_USE_BOTH);
printf("scanning %d module(s) against core on disk (%s)\n\n", count($targets), trim(shell_exec("php -r 'require \"$web/core/lib/Drupal.php\"; echo Drupal::VERSION;'") ?: '?'));

$fatal = 0;
$optional = 0;
foreach ($targets as $name => $r) {
  $cmd = sprintf('php -d display_errors=1 -d error_reporting=E_ALL %s --child %s %s 2>&1', escapeshellarg(__FILE__), escapeshellarg($name), escapeshellarg($mapfile));
  $out = shell_exec($cmd) ?? '';
  $json = NULL;
  if (preg_match('/\{"loaded".*\}$/s', trim($out), $m)) {
    $json = json_decode($m[0], TRUE);
  }
  if ($json === NULL) {
    $fatal++;
    $msg = preg_replace('/\s+/', ' ', trim(preg_replace('/^.*?(PHP )?Fatal error:\s*/s', '', $out)));
    printf("FATAL   %-34s %s\n", $name, substr($msg, 0, 260));
    continue;
  }
  if ($json['missing']) {
    $optional++;
    printf("missing %-34s %d class(es) need something not installed, e.g. %s\n", $name, count($json['missing']), substr($json['missing'][0], 0, 160));
  }
}
printf("\n%d module(s) FATAL on load, %d with uninstalled optional parents, %d clean\n", $fatal, $optional, count($targets) - $fatal - $optional);
exit($fatal ? 1 : 0);
