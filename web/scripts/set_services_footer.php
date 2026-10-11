<?php
/**
 * Closing copy under the cards on /services.
 *
 * Says to describe the symptom rather than pick a department, because what gets
 * reported and what is actually wrong are frequently in different ones.
 *
 * Written to the view's FOOTER area so it renders below the card grid. The
 * component CSS spans a footer across all grid columns — without that it would
 * sit inside one column beside the last card.
 *
 * Writes back the shape already stored (a Views area holds either a plain
 * string or value+format, and the two environments disagree), and reports
 * unchanged on a re-run.
 *
 * Idempotent; dry run unless BOS_SVCFOOTER_APPLY=1.
 */
$apply = getenv('BOS_SVCFOOTER_APPLY') === '1';
printf("%s\n\n", $apply ? 'APPLYING' : 'DRY RUN (BOS_SVCFOOTER_APPLY=1 to apply)');

$html = <<<'HTML'
<p>If you already know which of these you need, start there. If you do not, that is the more common situation and it is not a problem.</p>

<p><strong>What gets reported and what is actually wrong are often in different departments.</strong> A brown patch in a lawn comes in as a lawn problem and turns out to be a head that stopped turning. A tree going backwards in its third year gets blamed on the tree and is almost always water. A wet corner that will not dry is sometimes irrigation and sometimes grade, and those are two different crews and two different fixes.</p>

<p>So the useful thing to tell us is the symptom rather than the service. Describe what you are looking at, and we will work out which department it belongs to. If it belongs to two of them, they are both here.</p>

<p>Each category above opens into the specific work under it, if you would rather look first.</p>

<p><a href="/request-estimate">Tell us what you are seeing</a> and we will come and look at it.</p>
HTML;

$view = \Drupal::entityTypeManager()->getStorage('view')->load('services');
if (!$view) {
  print "  ABORT: the services view is missing.\n";
  return;
}
$display = $view->get('display');
$o = &$display['default']['display_options'];

// Match the header's stored shape, so this cannot convert one that works.
$hdr = $o['header']['area']['content'] ?? '';
$asArray = is_array($hdr);
$cur = $o['footer']['area']['content'] ?? NULL;
$old = is_array($cur) ? ($cur['value'] ?? '') : (string) ($cur ?? '');

printf("  header stored as: %s\n", $asArray ? 'value+format' : 'string');
printf("  footer: %s (%d -> %d chars)\n", $old === $html ? 'unchanged' : ($old === '' ? 'ADDING' : 'REWRITING'), strlen($old), strlen($html));

$o['footer']['area'] = [
  'id' => 'area', 'table' => 'views', 'field' => 'area', 'relationship' => 'none',
  'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'text',
  // Render it even when the view has rows; empty: FALSE means "not only when
  // the result is empty".
  'empty' => FALSE,
  'content' => $asArray ? ['value' => $html, 'format' => 'full_html'] : $html,
  'tokenize' => FALSE,
];
unset($o);

if ($apply) {
  $view->set('display', $display)->save();
}
print $apply ? "\ndone\n" : "\nnothing written\n";
