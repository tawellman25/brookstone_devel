<?php
/**
 * Season vocabulary — the verification checklist, as code. Read-only.
 */
$etm = \Drupal::entityTypeManager();
$sw = \Drupal::service('account_switcher');
$renderer = \Drupal::service('renderer');
$anon = new \Drupal\Core\Session\AnonymousUserSession();
$pass = 0; $fail = 0;
$ok = function (string $w, bool $g, string $x = '') use (&$pass, &$fail) {
  $g ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $g ? 'PASS' : 'FAIL', $w, $x !== '' ? " [$x]" : '');
};

$efm = \Drupal::service('entity_field.manager');
$s = $efm->getFieldDefinitions('taxonomy_term', 'season');
$m = $efm->getFieldDefinitions('taxonomy_term', 'material_types');
print "fields\n";
foreach (['field_short_description','field_public_description','field_teammate_description','field_call_to_action'] as $f) {
  $same = isset($s[$f]) && isset($m[$f])
    && $s[$f]->getType() === $m[$f]->getType()
    && (array) ($s[$f]->getSetting('allowed_formats') ?? []) === (array) ($m[$f]->getSetting('allowed_formats') ?? []);
  $ok("$f exists and matches material_types", $same);
}

print "\nshort description must not render on the term page\n";
foreach ($etm->getStorage('entity_view_display')->loadByProperties(['targetEntityType'=>'taxonomy_term','bundle'=>'season']) as $d) {
  $ok(sprintf('  %s display has no short description', $d->getMode()), !$d->getComponent('field_short_description'));
  $ok(sprintf('  %s display has no core description', $d->getMode()), !$d->getComponent('description'));
}

print "\nterms\n";
$terms = [];
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid'=>'season']) as $t) {
  $a = \Drupal::service('path_alias.repository')->lookupBySystemPath('/taxonomy/term/'.$t->id(),'en');
  $terms[$a ? basename($a['alias']) : strtolower($t->label())] = $t;
}
foreach ($terms as $slug => $t) {
  $ok(sprintf('  %-11s public description populated', $slug), !$t->get('field_public_description')->isEmpty(),
    strlen(trim(strip_tags((string) $t->get('field_public_description')->value))) . ' chars');
  $ok(sprintf('  %-11s core description cleared', $slug), $t->get('description')->isEmpty());
}

print "\nmeta descriptions still render\n";
foreach ($terms as $slug => $t) {
  $tags = \Drupal::service('metatag.manager')->tagsFromEntityWithDefaults($t);
  $el = \Drupal::service('metatag.manager')->generateRawElements($tags, $t);
  $desc = $el['description']['#attributes']['content'] ?? '';
  $ok(sprintf('  %-11s has one', $slug), $desc !== '', strlen($desc) . ' chars');
}

print "\naudience routing (Winter)\n";
$w = $terms['winter'];
$crew = substr(trim(strip_tags((string) $w->get('field_teammate_description')->value)), 0, 40);
foreach (['anon' => $anon, 'office' => $etm->getStorage('user')->load(1)] as $who => $acct) {
  $sw->switchTo($acct);
  $mode = 'full'; $ctx = [];
  \Drupal::moduleHandler()->alter('entity_view_mode', $mode, $w, $ctx);
  $html = (string) $renderer->renderPlain($etm->getViewBuilder('taxonomy_term')->view($w, $mode));
  $txt = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
  $hasCrew = $crew !== '' && str_contains($txt, $crew);
  if ($who === 'anon') {
    $ok('  anon gets full, no crew copy', $mode === 'full' && !$hasCrew, "mode=$mode");
    $ok('  anon sees no escaped markup', !str_contains($html, '&lt;p&gt;'));
    // CTA must come after the services list.
    $cta = substr(trim(strip_tags((string) $w->get('field_call_to_action')->value)), 0, 30);
    $posList = strpos($txt, 'Snow Removal');
    $posCta = $cta !== '' ? strpos($txt, $cta) : FALSE;
    $ok('  CTA renders BELOW the services list', $posList !== FALSE && $posCta !== FALSE && $posCta > $posList);
  }
  else {
    $ok('  office gets admin_view with crew copy', $mode === 'admin_view' && $hasCrew, "mode=$mode");
  }
  $sw->switchBack();
}

print "\npre-season\n";
$pre = $terms['pre-season'] ?? NULL;
$ok('  unpublished', $pre && !$pre->isPublished());
$sw->switchTo($anon);
$ok('  not viewable anonymously', $pre && !$pre->access('view', $anon));
$sw->switchBack();
$ok('  still has its services reference', $pre && !$pre->get('field_services_performed')->isEmpty());

print "\nservices references unchanged (baseline 37/27/29/8/1)\n";
$expect = ['spring'=>37,'summer'=>27,'fall'=>29,'winter'=>8,'pre-season'=>1];
foreach ($expect as $slug => $n) {
  $got = isset($terms[$slug]) ? count($terms[$slug]->get('field_services_performed')->getValue()) : -1;
  $ok(sprintf('  %-11s %d refs', $slug, $n), $got === $n, "got $got");
}
printf("\n%d passed, %d failed\n", $pass, $fail);
