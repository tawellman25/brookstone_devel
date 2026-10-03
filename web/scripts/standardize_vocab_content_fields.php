<?php

/**
 * One content-field shape for every public-facing vocabulary.
 *
 * Todd, 2026-10-03: every vocabulary carries a public description, a teammate
 * instruction and a short description, configured the same way, so neither
 * marketing nor Code has to ask which fields a vocabulary has before writing or
 * pasting.
 *
 * SCOPE: the 31 public-facing vocabularies (the bos_breadcrumbs allowlist, which
 * is the repo's existing definition of "renders a public page"). Operational
 * vocabularies are deliberately untouched — ~1,300 operational term pages exist
 * and adding three copy fields to all of them would bloat every term form for
 * no reader.
 *
 * CANONICAL CONFIG, copied from material_types where the fields already work:
 *   text_long, cardinality 1, allowed_formats ['full_html'], text_textarea
 *   widget with 5 rows.
 *
 * `allowed_formats` is the setting marketing flagged as the one that bites: a
 * vocabulary left open to a more restrictive format renders pasted HTML as
 * escaped text, and it looks like a copy problem rather than a field problem.
 * Pinning it to full_html on all of them removes the question.
 *
 * REQUIRED IS NORMALISED TO OPTIONAL EVERYWHERE, including on material_types,
 * which was the only vocabulary with field_public_description required. Relaxed
 * rather than spread: required would block the office saving a new term before
 * its copy exists, and new terms routinely arrive ahead of copy. All 38
 * material_types terms already carry one, so nothing is lost.
 *
 * Stored values whose format is not full_html are normalised, because a value
 * carrying a format the field no longer allows misbehaves on edit. That only
 * ever loosens filtering, so no markup is lost.
 *
 * VIEW DISPLAYS ARE NOT TOUCHED, with one named exception below: putting a
 * teammate instruction on a public display is the leak fixed on 2026-09-27, and
 * which fields render is a per-vocabulary decision.
 *
 * Usage:
 *   drush php:script web/scripts/standardize_vocab_content_fields.php
 *   BOS_STD_APPLY=1 drush php:script web/scripts/standardize_vocab_content_fields.php
 */

$apply = getenv('BOS_STD_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$efm = \Drupal::service('entity_field.manager');
$coverage = \Drupal::service('bos_content_coverage.coverage');

const STD_FIELDS = [
  'field_short_description' => [
    'label' => 'Short Description',
    'description' => 'One line, written to stand alone. This is what a list or card shows — never a trim of the body.',
    'weight' => 0,
  ],
  'field_public_description' => [
    'label' => 'Public Description',
    'description' => 'The public body copy for this term page.',
    'weight' => 1,
  ],
  'field_teammate_description' => [
    'label' => 'Teammate Instructions',
    'description' => 'Crew-facing. Never put this on a public display.',
    'weight' => 2,
  ],
];

// services keeps its own purpose-built pair (field_service_public_desc has a
// summary; field_service_crew_desc is the crew half). Adding the generic two
// alongside would create exactly the competing-body-fields problem just cleaned
// up, so it only gets the teaser it genuinely lacks.
const SKIP_FOR_VOCAB = [
  'services' => ['field_public_description', 'field_teammate_description'],
];

printf("MODE: %s\n\n", $apply ? 'APPLY' : 'DRY-RUN (set BOS_STD_APPLY=1 to write)');

$created = $relabelled = $formatsPinned = $widgets = $reformatted = $required = $formsCreated = 0;

foreach ($coverage->coveredVids() as $vid) {
  $defs = $efm->getFieldDefinitions('taxonomy_term', $vid);
  $skip = SKIP_FOR_VOCAB[$vid] ?? [];
  // Several vocabularies have no form-display CONFIG at all and fall back to
  // Drupal's implicit default. The fields are still editable there, but the
  // shape is then not explicit and not uniform, so the display is created.
  $form = $etm->getStorage('entity_form_display')->load('taxonomy_term.' . $vid . '.default');
  if (!$form) {
    $lines[] = sprintf('%-28s %s', '(form display)', 'CREATE — none existed');
    $form = \Drupal::service('entity_display.repository')->getFormDisplay('taxonomy_term', $vid, 'default');
    if ($apply) {
      $form->save();
      $formsCreated++;
    }
  }
  $lines = [];

  foreach (STD_FIELDS as $name => $spec) {
    if (in_array($name, $skip, TRUE)) {
      continue;
    }
    $field = $etm->getStorage('field_config')->load('taxonomy_term.' . $vid . '.' . $name);

    if (!$field) {
      $storage = $etm->getStorage('field_storage_config')->load('taxonomy_term.' . $name);
      if (!$storage) {
        $lines[] = sprintf('%-28s ** storage missing on taxonomy_term **', $name);
        continue;
      }
      $lines[] = sprintf('%-28s CREATE', $name);
      $created++;
      if ($apply) {
        $field = $etm->getStorage('field_config')->create([
          'field_storage' => $storage,
          'bundle' => $vid,
          'label' => $spec['label'],
          'description' => $spec['description'],
          'required' => FALSE,
          'settings' => ['allowed_formats' => ['full_html']],
        ]);
        $field->save();
      }
    }
    else {
      // Normalise an existing instance to the canonical shape.
      $fixes = [];
      if ($field->getLabel() !== $spec['label']) {
        $fixes[] = 'label "' . $field->getLabel() . '" -> "' . $spec['label'] . '"';
        $relabelled++;
        if ($apply) { $field->setLabel($spec['label']); }
      }
      $allowed = $field->getSetting('allowed_formats') ?: [];
      if ($allowed !== ['full_html']) {
        $fixes[] = 'allowed_formats ' . json_encode($allowed) . ' -> ["full_html"]';
        $formatsPinned++;
        if ($apply) { $field->setSetting('allowed_formats', ['full_html']); }
      }
      // Uniform means uniform: material_types had its public description
      // REQUIRED while every other vocabulary did not. Relaxed rather than
      // spread, because required would block the office saving a new term
      // before its copy exists, and new terms routinely arrive first. All 38
      // material_types terms already carry one, so nothing is lost.
      if ($field->isRequired()) {
        $fixes[] = 'required yes -> no';
        $required++;
        if ($apply) { $field->setRequired(FALSE); }
      }
      if ($fixes) {
        $lines[] = sprintf('%-28s %s', $name, implode('; ', $fixes));
        if ($apply) { $field->save(); }
      }
    }

    // On the term form, or the office cannot fill it.
    if ($form && !$form->getComponent($name)) {
      $lines[] = sprintf('%-28s add to the term form', $name);
      $widgets++;
      if ($apply) {
        $form->setComponent($name, [
          'type' => 'text_textarea',
          'weight' => $spec['weight'],
          'region' => 'content',
          'settings' => ['rows' => 5, 'placeholder' => ''],
          'third_party_settings' => [],
        ])->save();
      }
    }
  }

  if ($lines) {
    printf("%s\n", $vid);
    foreach ($lines as $l) { printf("   %s\n", $l); }
  }
}

// --- stored values whose format the field no longer allows ------------------
print "\n=== stored text formats ===\n";
if ($apply) { $efm->clearCachedFieldDefinitions(); }
foreach ($coverage->coveredVids() as $vid) {
  foreach (array_keys(STD_FIELDS) as $name) {
    if (in_array($name, SKIP_FOR_VOCAB[$vid] ?? [], TRUE)) { continue; }
    $changed = 0;
    foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid]) as $t) {
      if (!$t->hasField($name) || $t->get($name)->isEmpty()) { continue; }
      $v = $t->get($name)->first()->getValue();
      if (($v['format'] ?? '') === 'full_html') { continue; }
      $changed++;
      if ($apply) {
        $t->set($name, ['value' => $v['value'], 'format' => 'full_html'])->save();
      }
    }
    if ($changed) {
      printf("   %-28s %-28s %d value(s) -> full_html\n", $vid, $name, $changed);
      $reformatted += $changed;
    }
  }
}

// --- the one view-display change, named and justified ----------------------
print "\n=== named exception: unhide field_short_description on plant_characteristics ===\n";
print "   (marketing's brief: it exists but is hidden on all five view modes)\n";
foreach (['default','full','teammate_view','admin_view','client_view'] as $vm) {
  $d = $etm->getStorage('entity_view_display')->load('taxonomy_term.plant_characteristics.' . $vm);
  if (!$d) { continue; }
  if ($d->getComponent('field_short_description')) {
    printf("   %-16s already shown\n", $vm);
    continue;
  }
  printf("   %-16s UNHIDE (above the body)\n", $vm);
  if ($apply) {
    $d->setComponent('field_short_description', [
      'type' => 'text_default',
      'label' => 'hidden',
      // Above field_public_description, which sits at 0.
      'weight' => -1,
      'region' => 'content',
      'settings' => [],
      'third_party_settings' => [],
    ])->save();
  }
}

printf("\n%d instance(s) created, %d relabelled, %d pinned to full_html, %d made optional, %d form display(s) created, %d widgets added, %d stored values normalised to full_html\n",
  $created, $relabelled, $formatsPinned, $required, $formsCreated, $widgets, $reformatted);
if (!$apply) { print "\nNothing written. Re-run with BOS_STD_APPLY=1 to apply.\n"; }
