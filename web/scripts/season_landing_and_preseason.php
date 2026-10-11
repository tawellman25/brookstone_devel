<?php
/**
 * The Seasons landing page copy, and taking Pre-season off the public site.
 *
 * The landing intro is a VIEWS HEADER, not a term field — `land_seasons:page_1`,
 * the same pattern as /about-us/our-equipment. So the header and footer copy go
 * there, with the page's metatags on the display's metatag extender.
 *
 * Pre-season: Todd's decision of 11 October. It stays in BOS and in the select
 * lists, and comes off the public site — "pre-season" is contractor language, a
 * homeowner in February is thinking about winter, and nobody searches it. Its
 * best argument was not lost: the dormant-window paragraphs moved to Winter and
 * Spring in the copy load.
 *
 * ⚠ Unpublishing it hides any service tagged ONLY to Pre-season, so this reports
 * that list rather than leaving it to be discovered.
 *
 * Dry run by default. BOS_SEASON_LANDING_APPLY=1 to write.
 */
require __DIR__ . '/bos_seasons_copy_parser.inc.php';

$apply = getenv('BOS_SEASON_LANDING_APPLY') === '1';
$md = getenv('BOS_SEASON_MD') ?: '/tmp/seasons.md';
$reloc = getenv('BOS_SEASON_RELOC') ?: '/tmp/relocation.md';
$etm = \Drupal::entityTypeManager();
printf("%s\n\n", $apply ? '*** APPLYING ***' : 'DRY RUN (BOS_SEASON_LANDING_APPLY=1 to apply)');
$copy = bos_seasons_parse($md, $reloc);

// --- 1. the landing view --------------------------------------------------
print "1. landing page — land_seasons:page_1\n";
$view = $etm->getStorage('view')->load('land_seasons');
if (!$view) { print "   VIEW MISSING\n"; }
else {
  $display = $view->get('display');
  $target = isset($display['page_1']['display_options']['header']) ? 'page_1' : 'default';
  $header = $copy['landing']['header'] ?? '';
  $footer = $copy['landing']['footer'] ?? '';
  printf("   header: %d chars -> %s display\n   footer: %d chars\n", strlen($header), $target, strlen($footer));
  printf("   title : %s\n   meta  : %s\n", $copy['landing']['title'] ?? '-', substr($copy['landing']['description'] ?? '-', 0, 80));
  if ($apply) {
    foreach (['header' => $header, 'footer' => $footer] as $area => $content) {
      if ($content === '') { continue; }
      // ⚠ content is an ARRAY of value+format, not a string.
      //
      // The area handler plugin comes from views DATA, not from the plugin_id
      // written into config -- the same rule that makes a `plugin_id` on a
      // list_string filter unable to swap its handler. So this is always the
      // `text` plugin, whatever plugin_id says, and `text` reads
      // $this->options['content']['value']. Writing a bare string here threw
      // "Cannot access offset of type string on string" out of
      // area\Text::preQuery() and took the page to a 500.
      $display[$target]['display_options'][$area]['area'] = [
        'id' => 'area', 'table' => 'views', 'field' => 'area', 'plugin_id' => 'text',
        'empty' => TRUE, 'tokenize' => FALSE,
        'content' => ['value' => $content, 'format' => 'full_html'],
      ];
      $display[$target]['display_options']['defaults'][$area] = FALSE;
    }
    // Page metatags: write to EVERY display that already overrides them, or the
    // page keeps its own stale set. page_1 here carried its own.
    if (!empty($copy['landing']['title'])) {
      $tags = [
        'title' => $copy['landing']['title'],
        'description' => $copy['landing']['description'],
        'og_description' => $copy['landing']['description'],
      ];
      foreach (array_keys($display) as $did) {
        $has = isset($display[$did]['display_options']['display_extenders']['metatag_display_extender']);
        if ($did !== $target && !$has) { continue; }
        $display[$did]['display_options']['display_extenders']['metatag_display_extender']['metatags'] = $tags;
        $display[$did]['display_options']['display_extenders']['metatag_display_extender']['tokenize'] = FALSE;
        if ($did !== 'default') { $display[$did]['display_options']['defaults']['display_extenders'] = FALSE; }
        printf("   metatags written to %s\n", $did);
      }
    }
    $view->set('display', $display);
    $view->save();
    print "   written\n";
  }
}

// --- 2. services that would vanish ---------------------------------------
print "\n2. services whose ONLY season is Pre-season (they would disappear)\n";
$pre = NULL;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'season']) as $t) {
  if (stripos($t->label(), 'pre') === 0) { $pre = $t; }
}
$orphans = [];
if ($pre) {
  // Which services does Pre-season list, and do they appear on another season?
  foreach ($pre->get('field_services_performed')->referencedEntities() as $svc) {
    $others = [];
    foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'season']) as $s) {
      if ($s->id() === $pre->id()) { continue; }
      foreach ($s->get('field_services_performed')->getValue() as $ref) {
        if ((int) $ref['target_id'] === (int) $svc->id()) { $others[] = $s->label(); }
      }
    }
    printf("   %-40s also on: %s\n", substr($svc->label(), 0, 40), $others ? implode(', ', $others) : '*** NOTHING ELSE ***');
    if (!$others) { $orphans[] = $svc->label(); }
  }
}
if ($orphans) { printf("   ⚠ %d service(s) would become invisible: %s\n", count($orphans), implode(', ', $orphans)); }
else { print "   none — safe to unpublish\n"; }

// --- 3. unpublish pre-season ---------------------------------------------
print "\n3. Pre-season — off the public site, kept in BOS\n";
if (!$pre) { print "   term not found\n"; }
else {
  printf("   %s (%s) currently %s\n", $pre->label(), $pre->id(), $pre->isPublished() ? 'published' : 'already unpublished');
  if ($apply) {
    if ($pre->isPublished()) { $pre->setUnpublished(); $pre->save(); print "   unpublished\n"; }
    $db = \Drupal::database();
    if ($db->schema()->tableExists('xmlsitemap')) {
      $db->update('xmlsitemap')->fields(['status' => 0])
        ->condition('type', 'taxonomy_term')->condition('id', $pre->id())->execute();
      print "   excluded from the sitemap\n";
    }
  }
}
print "\ndone" . ($apply ? '' : ' (dry run)') . "\n";
