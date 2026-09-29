<?php
declare(strict_types=1);
/**
 * Restore the pre-reparent juniper path.
 *
 * This morning's Plants reparent left a redirect /material/trees/junipers ->
 * the Junipers TERM. Dissolving that category deleted the term, which left the
 * redirect pointing at nothing — so the oldest juniper URL, the one most likely
 * to be in a bookmark or an external link, went from redirecting to 404 while
 * the newer /material/plants/trees/junipers kept working.
 *
 * Repoints it at the surviving Juniper category, resolved at run time.
 * Idempotent; BOS_JREDIR_APPLY=1 to write.
 */
$apply = getenv('BOS_JREDIR_APPLY') === '1';
$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$target = NULL;
foreach ($terms->loadByProperties(['vid' => 'material_types', 'name' => 'Juniper']) as $t) {
  $target = $t;
}
if (!$target) {
  print "no Juniper category — aborting.\n";
  return;
}
$uri = 'internal:/taxonomy/term/' . $target->id();
$storage = \Drupal::entityTypeManager()->getStorage('redirect');

foreach (['material/trees/junipers'] as $source) {
  $existing = $storage->loadByProperties(['redirect_source__path' => $source]);
  if ($existing) {
    foreach ($existing as $r) {
      $current = $r->get('redirect_redirect')->uri;
      if ($current === $uri) {
        printf("  ok      %s already -> %s\n", $source, $uri);
        continue;
      }
      printf("  %s %s: %s -> %s\n", $apply ? 'REPOINT' : 'would  ', $source, $current, $uri);
      if ($apply) {
        $r->set('redirect_redirect', ['uri' => $uri]);
        $r->save();
      }
    }
    continue;
  }
  printf("  %s create %s -> %s\n", $apply ? 'CREATE ' : 'would  ', $source, $uri);
  if ($apply) {
    $storage->create([
      'redirect_source' => ['path' => $source, 'query' => []],
      'redirect_redirect' => ['uri' => $uri],
      'language' => 'und',
      'status_code' => 301,
    ])->save();
  }
}
if (!$apply) { print "\nDRY RUN. BOS_JREDIR_APPLY=1 to write.\n"; }
