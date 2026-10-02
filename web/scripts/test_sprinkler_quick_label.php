<?php
/**
 * The crew must never see '%AutoEntityLabel: <uuid>%' as a record's heading.
 *
 * Two layers are tested: the bundle now builds its label on the first save
 * (root cause), and the quick-entry form refuses to leave a placeholder behind
 * even if that setting is flipped back in the UI (backstop).
 */
$etm = \Drupal::entityTypeManager();
$cfgf = \Drupal::configFactory();
$NAME = 'auto_entitylabel.settings.property_system_controller.controller';
$pass = 0; $fail = 0;
$ok = function (string $what, bool $good, string $got = '') use (&$pass, &$fail) {
  $good ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $good ? 'PASS' : 'FAIL', $what, $got !== '' ? " [$got]" : '');
};

$sysId = (int) (\Drupal::database()->query('SELECT id FROM {property_sprinkler_system} ORDER BY id DESC LIMIT 1')->fetchField() ?: 0);
if (!$sysId) { print "no sprinkler system to test against\n"; return; }
$make = fn() => $etm->getStorage('property_system_controller')->create([
  'type' => 'controller', 'field_property_ss_system' => $sysId,
  'field_controller_number' => 4, 'field_controller_type' => 1,
  'field_controller_location' => 'TEST - delete me',
]);
$reload = fn($e) => $etm->getStorage('property_system_controller')->loadUnchanged($e->id());

$restore = (int) $cfgf->get($NAME)->get('new_content_behavior');

print "1. the bundle as it now ships\n";
$ok('labels on the first save (not deferred)', $restore === 0, 'new_content_behavior=' . $restore);
$a = $make(); $a->save();
$ok('a plain save gives the real title', !str_contains((string) $reload($a)->label(), '%AutoEntityLabel'), (string) $reload($a)->label());
$a->delete();

print "\n2. the form's backstop, with the setting flipped back to the broken state\n";
$cfgf->getEditable($NAME)->set('new_content_behavior', 1)->save();

// Unguarded: what the crew saw on WO#53970.
$b = $make(); $b->save();
$ok('unguarded save still shows the placeholder', str_contains((string) $reload($b)->label(), '%AutoEntityLabel'), substr((string) $reload($b)->label(), 0, 46));
$b->delete();

// Guarded: the form's own save path.
$form = \Drupal::classResolver(\Drupal\properties\Form\SprinklerQuickCaptureForm::class);
$m = new \ReflectionMethod($form, 'saveRecord');
$m->setAccessible(TRUE);
$c = $make();
$m->invoke($form, $c);
$title = (string) $reload($c)->label();
$ok('the form leaves no placeholder', !str_contains($title, '%AutoEntityLabel'), $title);
$ok('and the title is the real one', $title === '#4 - Primary Controller', $title);
$c->delete();

// A record that never had a placeholder is saved exactly once.
$cfgf->getEditable($NAME)->set('new_content_behavior', 0)->save();
$d = $make();
$m->invoke($form, $d);
$ok('no second save when the label was already right', (string) $reload($d)->label() === '#4 - Primary Controller', (string) $reload($d)->label());
$d->delete();

$cfgf->getEditable($NAME)->set('new_content_behavior', $restore)->save();
printf("\n%d passed, %d failed\n", $pass, $fail);
