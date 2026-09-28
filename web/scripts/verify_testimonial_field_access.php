<?php
// Reversible check: create a testimonial with a scan + email, render it as
// each audience, then delete it.
$ur = \Drupal::entityTypeManager()->getStorage('user');
$pick = function(array $roles) use ($ur) {
  if ($roles === []) return \Drupal\user\Entity\User::getAnonymousUser();
  foreach ($ur->loadByProperties(['status' => 1]) as $u) {
    if (array_intersect($roles, $u->getRoles()) && !array_intersect(['administrator','site_admin'], $u->getRoles())) return $u;
  }
  return NULL;
};
$audiences = [
  'anonymous' => \Drupal\user\Entity\User::getAnonymousUser(),
  'client'    => $pick(['client']),
  'teammate'  => $pick(['teammates']),
  'office'    => $pick(['administration']),
];

// Create AS OFFICE — otherwise the presave backstop forces `pending` and the
// public view has zero rows, which would "pass" without rendering anything.
$as = \Drupal::service('account_switcher');
$as->switchTo($audiences['office']);
$t = \Drupal::entityTypeManager()->getStorage('testimonial')->create([
  'type' => 'client',
  'field_testimony' => ['value' => 'They showed up when they said they would.', 'format' => 'basic_html'],
  'field_testimonial_by' => 'Test Customer',
  'field_status' => 'approved',
  'field_submitter_email' => 'private@example.com',
  'status' => 1,
]);
$t->save();
$as->switchBack();
printf("created testimonial %d (status=%s, published=%d)\n\n", $t->id(), $t->get('field_status')->value, $t->isPublished());

printf("%-11s %-24s %-24s %s\n", 'audience', 'scan', 'submitter_email', 'testimony(public)');
foreach ($audiences as $name => $acct) {
  if (!$acct) { printf("  %-9s (no such user)\n", $name); continue; }
  $r = [];
  foreach (['field_testimonial_scan','field_submitter_email','field_testimony'] as $f) {
    $withItems = $t->get($f)->access('view', $acct) ? 'ALLOW' : 'deny';
    $itemless  = \Drupal::service('entity_field.manager')->getFieldDefinitions('testimonial','client')[$f];
    $r[] = $withItems;
  }
  printf("%-11s %-24s %-24s %s\n", $name, $r[0], $r[1], $r[2]);
}

// Render the public view as anon and look for either private value.
$acct = \Drupal\user\Entity\User::getAnonymousUser();
$as->switchTo($acct);
$view = \Drupal\views\Views::getView('testimonials');
$view->setDisplay('page_site');
$view->preExecute(); $view->execute();
$out = (string) \Drupal::service('renderer')->renderInIsolation($view->buildRenderable('page_site'));
$as->switchBack();
print "\n=== public /about-us/reviews rendered as anon ===\n";
printf("  rows                       %d\n", count($view->result));
printf("  quote present              %s\n", str_contains($out, 'showed up when they said') ? 'yes' : 'NO');
printf("  submitter email leaked     %s\n", str_contains($out, 'private@example.com') ? 'LEAK' : 'no');
printf("  scan field leaked          %s\n", str_contains($out, 'testimonials/scans') ? 'LEAK' : 'no');

$t->delete();
print "\ncleaned up test testimonial\n";
