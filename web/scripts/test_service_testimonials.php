<?php
// Renders a services term page as an anonymous visitor, with and without an
// approved review for that service. Self-cleaning.
$etm = \Drupal::entityTypeManager();
$terms = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'services']);
$repair = NULL; $other = NULL;
foreach ($terms as $t) {
  if (strtolower($t->label()) === 'repair' && !$repair) { $repair = $t; continue; }
  if (!$other && strtolower($t->label()) !== 'repair') { $other = $t; }
}
if (!$repair) { print "ABORT: no 'Repair' services term on this env\n"; return; }
printf("repair term %s (%s)\nother  term %s (%s)\n\n", $repair->id(), $repair->toUrl()->toString(), $other?->id(), $other?->toUrl()->toString());

$anon = \Drupal\user\Entity\User::getAnonymousUser();
$switcher = \Drupal::service('account_switcher');
$render = function ($term) use ($etm, $anon, $switcher) {
  $switcher->switchTo($anon);
  \Drupal::service('cache_tags.invalidator')->invalidateTags($term->getCacheTags());
  $build = $etm->getViewBuilder('taxonomy_term')->view($term, 'full');
  $html = (string) \Drupal::service('renderer')->renderInIsolation($build);
  $switcher->switchBack();
  return $html;
};

print "=== BEFORE (no approved review for Repair) ===\n";
$h = $render($repair);
printf("  heading present : %s\n", str_contains($h, 'What customers say') ? 'yes' : 'no (good)');

// Create one, as office so the presave backstop leaves it approved.
$office = NULL;
foreach ($etm->getStorage('user')->loadByProperties(['status' => 1]) as $u) {
  if (in_array('administration', $u->getRoles(), TRUE)) { $office = $u; break; }
}
$switcher->switchTo($office);
$t = $etm->getStorage('testimonial')->create([
  'type' => 'client',
  'field_testimony' => ['value' => 'They fixed a broken head the same afternoon I called.', 'format' => 'basic_html'],
  'field_testimonial_by' => 'A Customer',
  'field_testimony_service' => ['target_id' => $repair->id()],
  'field_status' => 'approved',
  'status' => 1,
]);
$t->save();
$pending = $etm->getStorage('testimonial')->create([
  'type' => 'client',
  'field_testimony' => ['value' => 'UNREVIEWED TEXT FROM A STRANGER', 'format' => 'basic_html'],
  'field_testimony_service' => ['target_id' => $repair->id()],
  'field_status' => 'pending',
  'status' => 1,
]);
$pending->save();
$switcher->switchBack();
drupal_flush_all_caches();

print "\n=== AFTER (1 approved + 1 pending for Repair) ===\n";
$h = $render($repair);
printf("  heading names the service : %s\n", str_contains($h, 'What customers say about ' . $repair->label()) ? 'yes' : 'NO');
printf("  approved quote shown      : %s\n", str_contains($h, 'broken head the same afternoon') ? 'yes' : 'NO');
printf("  PENDING text leaked       : %s\n", str_contains($h, 'UNREVIEWED TEXT') ? 'LEAK' : 'no');
printf("  card class present        : %s\n", str_contains($h, 'review-card') ? 'yes' : 'NO');
printf("  service-reviews wrapper   : %s\n", str_contains($h, 'service-reviews') ? 'yes' : 'NO');

if ($other) {
  print "\n=== OTHER SERVICE (" . $other->label() . ") ===\n";
  $h2 = $render($other);
  printf("  heading present  : %s\n", str_contains($h2, 'What customers say') ? 'LEAK' : 'no (good)');
  printf("  repair quote here: %s\n", str_contains($h2, 'broken head the same afternoon') ? 'LEAK' : 'no (good)');
}

$t->delete(); $pending->delete();
drupal_flush_all_caches();
print "\ncleaned up\n";
