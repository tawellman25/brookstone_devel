<?php

declare(strict_types=1);

/**
 * Is Layout Builder actually USED anywhere? (read-only — safe on live)
 *
 * "Enabled on a display" is not the same as "used". Four distinct things have to
 * be empty before layout_builder can be disabled without changing a page:
 *
 *  1. A display with layout_builder enabled but NO sections is inert — fields
 *     render through the ordinary display either way.
 *  2. A display with SECTIONS has a hand-arranged default layout. Disabling
 *     reverts it to plain field rendering, which is a visible change.
 *  3. allow_custom lets editors override a layout PER ENTITY; those overrides
 *     live in {entity_type}__layout_builder__layout tables.
 *  4. Inline blocks are block_content entities with reusable = 0. They exist
 *     ONLY inside a layout, so losing the layout orphans their content.
 *
 *   drush php:script web/scripts/audit_layout_builder_usage.php
 */

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();

$enabled = $withSections = $allowCustom = 0;
$detail = [];

foreach ($etm->getStorage('entity_view_display')->loadMultiple() as $id => $d) {
  $lb = $d->getThirdPartySettings('layout_builder');
  if (empty($lb['enabled'])) {
    continue;
  }
  $enabled++;
  $sections = $lb['sections'] ?? [];
  $components = 0;
  foreach ($sections as $s) {
    // Sections deserialize to Section objects via the display's third-party
    // settings; count components either way.
    if (is_object($s) && method_exists($s, 'getComponents')) {
      $components += count($s->getComponents());
    }
    elseif (is_array($s)) {
      $components += count($s['components'] ?? []);
    }
  }
  if ($sections) {
    $withSections++;
  }
  if (!empty($lb['allow_custom'])) {
    $allowCustom++;
  }
  if ($sections || !empty($lb['allow_custom'])) {
    $detail[] = sprintf('%-52s sections=%-2d components=%-3d allow_custom=%s',
      $id, count($sections), $components, !empty($lb['allow_custom']) ? 'YES' : 'no');
  }
}

printf("Displays with layout_builder ENABLED : %d\n", $enabled);
printf("  ...of those, carrying SECTIONS     : %d   <- a real arranged layout\n", $withSections);
printf("  ...of those, allow_custom          : %d   <- per-entity overrides permitted\n", $allowCustom);

if ($detail) {
  print "\nDisplays that are not inert:\n";
  foreach ($detail as $line) {
    print "  $line\n";
  }
}

// --- 3. stored per-entity overrides ----------------------------------------
print "\nStored layout overrides (field tables):\n";
$found = FALSE;
foreach ($db->query("SHOW TABLES LIKE '%layout_builder%'")->fetchCol() as $t) {
  $n = (int) $db->query("SELECT COUNT(*) FROM {$t}")->fetchField();
  printf("  %-56s %d rows%s\n", $t, $n, $n ? '   <-- IN USE' : '');
  $found = TRUE;
}
if (!$found) {
  print "  none — no layout_builder field tables exist at all\n";
}

// --- 4. inline blocks -------------------------------------------------------
print "\nInline blocks (block_content with reusable = 0):\n";
try {
  $rows = $db->select('block_content_field_data', 'b')
    ->fields('b', ['type'])
    ->condition('reusable', 0)
    ->groupBy('type')
    ->execute()
    ->fetchAll();
  if (!$rows) {
    print "  none — nothing was ever placed inline in a layout\n";
  }
  else {
    foreach ($rows as $r) {
      $c = (int) $db->select('block_content_field_data', 'b')
        ->condition('reusable', 0)
        ->condition('type', $r->type)
        ->countQuery()->execute()->fetchField();
      printf("  %-30s %d   <-- IN USE\n", $r->type, $c);
    }
  }
}
catch (\Throwable $e) {
  print '  (could not read block_content_field_data: ' . $e->getMessage() . ")\n";
}

// --- context: what disabling would buy -------------------------------------
$defs = \Drupal::service('plugin.manager.block')->getDefinitions();
$lbDefs = array_filter($defs, fn($d) => str_starts_with($d['provider'] ?? '', 'layout_builder')
  || str_contains($d['id'] ?? '', 'inline_block')
  || str_contains($d['id'] ?? '', 'field_block'));
printf("\nBlock plugin definitions: %d total, %d from layout_builder (%.1f%%)\n",
  count($defs), count($lbDefs), count($defs) ? count($lbDefs) / count($defs) * 100 : 0);
