<?php
/**
 * Load the Rev. 6 season copy.
 *
 * Parsed out of the markdown, never retyped — 24 KB of transcription is the one
 * step guaranteed to introduce a difference nobody notices until a customer
 * reads it.
 *
 * Rev. 6 is self-contained: no borrowed Pre-season paragraphs, so the relocation
 * logic the Rev. 5 loader carried does not apply and is deliberately absent
 * rather than ported.
 *
 * Writes:
 *   - 4 published seasons x field_short_description / field_public_description /
 *     field_teammate_description / field_call_to_action, plus page title and
 *     meta description.
 *   - the landing view's header and footer areas, plus its title/description.
 *
 * Pre-season is untouched: it is unpublished and Rev. 6 supplies no copy for it.
 *
 * Previous values are written to docs/backups/ before anything changes.
 *
 * Dry run unless BOS_SEASON6_APPLY=1.  Source: BOS_SEASON6_MD.
 */
$apply = getenv('BOS_SEASON6_APPLY') === '1';
$md = getenv('BOS_SEASON6_MD') ?: '';
if ($md === '' || !is_readable($md)) {
  print "  ABORT: set BOS_SEASON6_MD to the readable markdown source.\n";
  return;
}
printf("%s\n\n", $apply ? '*** APPLYING ***' : 'DRY RUN (BOS_SEASON6_APPLY=1 to apply)');

// ---- parse -------------------------------------------------------------
$raw = file_get_contents($md);
// Split on top-level "# " headings.
$parts = preg_split('/^# (.+)$/m', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
$sections = [];
for ($i = 1; $i < count($parts); $i += 2) {
  $sections[trim($parts[$i])] = $parts[$i + 1];
}

/** Pull a fenced block that follows a "## <label>" heading. */
$block = function (string $body, string $label): ?string {
  $q = preg_quote($label, '/');
  if (!preg_match('/^## ' . $q . '[^\n]*\n+```(?:html)?\n(.*?)\n```/ms', $body, $m)) {
    return NULL;
  }
  return trim($m[1]);
};

$SEASONS = ['Spring', 'Summer', 'Fall', 'Winter'];
$FIELDS = ['field_short_description', 'field_public_description', 'field_teammate_description', 'field_call_to_action'];
$copy = [];
foreach ($sections as $head => $body) {
  foreach ($SEASONS as $s) {
    if (!str_starts_with($head, $s . ' ')) {
      continue;
    }
    $row = ['title' => $block($body, 'Page title'), 'desc' => $block($body, 'Meta description')];
    foreach ($FIELDS as $f) {
      $row[$f] = $block($body, '`' . $f . '`');
    }
    $copy[strtolower($s)] = $row;
  }
  if (str_starts_with($head, 'LANDING PAGE')) {
    $copy['_landing'] = [
      'header' => $block($body, 'View header'),
      'footer' => $block($body, 'View footer'),
      'title' => $block($body, 'Page title'),
      'desc' => $block($body, 'Meta description'),
    ];
  }
}

// ---- gate: every piece must have parsed --------------------------------
$missing = [];
foreach ($SEASONS as $s) {
  $k = strtolower($s);
  foreach (array_merge($FIELDS, ['title', 'desc']) as $f) {
    if (empty($copy[$k][$f])) {
      $missing[] = "$k.$f";
    }
  }
}
foreach (['header', 'footer', 'title', 'desc'] as $f) {
  if (empty($copy['_landing'][$f])) {
    $missing[] = "landing.$f";
  }
}
if ($missing) {
  printf("  ABORT: %d block(s) did not parse — writing nothing.\n", count($missing));
  foreach ($missing as $m) {
    print "    missing: $m\n";
  }
  return;
}
// A marker left in by the author must never reach a live field.
foreach ($copy as $k => $row) {
  foreach ($row as $f => $v) {
    if (is_string($v) && (str_contains($v, '[CONFIRM') || str_contains($v, '**'))) {
      printf("  ABORT: %s.%s carries an unresolved marker or raw markdown.\n", $k, $f);
      return;
    }
  }
}
printf("  parsed: 4 seasons x 6 pieces + landing header/footer/title/description\n\n");

// ---- write -------------------------------------------------------------
$etm = \Drupal::entityTypeManager();
$backup = [];
$terms = [];
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'season']) as $t) {
  $terms[strtolower($t->label())] = $t;
}

foreach ($SEASONS as $s) {
  $k = strtolower($s);
  $t = $terms[$k] ?? NULL;
  if (!$t) {
    printf("  %-8s *** no term — skipped ***\n", $s);
    continue;
  }
  printf("  %-8s", $s);
  $bits = [];
  foreach ($FIELDS as $f) {
    if (!$t->hasField($f)) {
      $bits[] = "$f MISSING";
      continue;
    }
    $old = (string) $t->get($f)->value;
    $new = $copy[$k][$f];
    $backup["$k.$f"] = $old;
    $bits[] = sprintf('%s %d->%d', str_replace('field_', '', $f), strlen($old), strlen($new));
    if ($apply) {
      // Every one of these is Full HTML by the 2026-10-11 standardisation.
      $t->set($f, ['value' => $new, 'format' => 'full_html']);
    }
  }
  // Metatags: merge so any other stored tag survives.
  if ($t->hasField('field_meta_tags')) {
    // Stored as JSON on this site — verified on all four terms. serialize()
    // here would write a format Metatag cannot read back.
    $rawTags = (string) $t->get('field_meta_tags')->value;
    $tags = $rawTags !== '' ? (json_decode($rawTags, TRUE) ?: []) : [];
    if ($rawTags !== '' && !$tags) {
      printf("\n  ABORT: %s has metatags in an unrecognised format — not JSON.\n", $k);
      return;
    }
    $backup["$k.meta"] = $tags;
    // Merge, so any other stored tag survives.
    $tags['title'] = $copy[$k]['title'];
    $tags['description'] = $copy[$k]['desc'];
    $tags['og_description'] = $copy[$k]['desc'];
    $bits[] = 'meta set';
    if ($apply) {
      $t->set('field_meta_tags', json_encode($tags));
    }
  }
  print '  ' . implode(' · ', $bits) . "\n";
  if ($apply) {
    $t->save();
  }
}

// ---- landing view ------------------------------------------------------
$view = $etm->getStorage('view')->load('land_season') ?: $etm->getStorage('view')->load('season_landing');
if (!$view) {
  foreach ($etm->getStorage('view')->loadMultiple() as $v) {
    foreach ($v->get('display') as $d) {
      if (($d['display_options']['path'] ?? '') === 'about-us/seasons') {
        $view = $v;
        break 2;
      }
    }
  }
}
if (!$view) {
  print "\n  *** landing view not found — header/footer NOT written ***\n";
}
else {
  printf("\n  landing view: %s\n", $view->id());
  $display = $view->get('display');
  foreach ($display as $id => &$d) {
    foreach (['header' => 'header', 'footer' => 'footer'] as $region => $key) {
      if (!isset($d['display_options'][$key]['area'])) {
        continue;
      }
      $cur = $d['display_options'][$key]['area']['content'] ?? '';
      $wasArray = is_array($cur);
      $old = $wasArray ? ($cur['value'] ?? '') : (string) $cur;
      $backup["landing.$id.$key"] = $cur;
      $new = $copy['_landing'][$region];
      printf("    %-12s %-7s %s (%d -> %d chars, stored as %s)\n", $id, $key,
        $old === $new ? 'unchanged' : 'REWRITTEN', strlen($old), strlen($new),
        $wasArray ? 'value+format' : 'string');
      // Write back the SHAPE that is already there. Both work here — the live
      // areas hold a plain string and render fine — and converting shape is a
      // change nobody asked for on a page that is already correct.
      $d['display_options'][$key]['area']['content'] = $wasArray
        ? ['value' => $new, 'format' => $cur['format'] ?? 'full_html']
        : $new;
    }
    // Metatags live on EVERY display that overrides them, not just default.
    if (isset($d['display_options']['display_extenders']['metatag_display_extender']['metatags'])) {
      $m = &$d['display_options']['display_extenders']['metatag_display_extender']['metatags'];
      $backup["landing.$id.meta"] = $m;
      $m['title'] = $copy['_landing']['title'];
      $m['description'] = $copy['_landing']['desc'];
      $m['og_description'] = $copy['_landing']['desc'];
      printf("    %-12s meta    set\n", $id);
      unset($m);
    }
  }
  unset($d);
  if ($apply) {
    $view->set('display', $display)->save();
  }
}

// ---- backup ------------------------------------------------------------
if ($apply) {
  $dir = 'public://../../../docs/backups';
  $path = DRUPAL_ROOT . '/../docs/backups';
  @mkdir($path, 0775, TRUE);
  $file = $path . '/season-copy-pre-rev6-' . date('Ymd-His') . '.json';
  if (file_put_contents($file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === FALSE) {
    print "\n  *** BACKUP FAILED — previous values were still overwritten ***\n";
  }
  else {
    printf("\n  previous values: %s\n", $file);
  }
}
print $apply ? "\ndone\n" : "\nnothing written\n";
