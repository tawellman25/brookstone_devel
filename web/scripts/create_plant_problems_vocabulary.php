<?php

/**
 * @file
 * Phase 1: create the `plant_problems` vocabulary and the one Spruce Needle
 * Drop term. Nothing else.
 *
 * Spec: "Plant Problems Vocabulary and Tree Spraying Consolidation.md" Phase 1.
 * Copy: "Spruce Needle Drop Page Copy.md" (revised 2026-11-03).
 *
 * WHY A SEPARATE VOCABULARY. Every term in `services` implies a Work Order
 * type through the BOS invariant:
 *
 *     work_order.bundle = work_order.field_service.term.field_service_bundle
 *
 * A page explaining why a spruce browns in October is reference content, not a
 * billable line. Putting it in `services` creates an operational artifact — a
 * Work Order type nobody opens — to hold an article. (It was briefly put there
 * on 2026-11-03 and reversed the same session; see the change log.)
 *
 * Requirement 1 — "must not be referenceable by work_order.field_service" — is
 * satisfied BY CONSTRUCTION and verified, not assumed: all 36
 * `work_order.*.field_service` instances have handler_settings.target_bundles
 * explicitly ['services']. This script asserts that before it writes anything,
 * because the requirement is the whole reason the vocabulary exists.
 *
 * ⚠ KNOWN, REPORTED, DELIBERATELY NOT FIXED HERE: 35 OTHER `field_service`
 * instances (contract_sections 24, sop 9, estimate_request 1, service_request
 * 1) have no target_bundles at all, so they offer every vocabulary and will
 * start offering plant problems too. That is a pre-existing bug this
 * vocabulary makes visible. Out of scope for this pass by instruction.
 *
 * HOW IT IS BUILT. The `season` vocabulary is the approved pattern, so the
 * eight field instances and the four view displays are CLONED from it rather
 * than hand-written. All eight field STORAGES already exist on taxonomy_term,
 * so this adds instances only — no new storage, and the content model stays
 * "identically configured" across public vocabularies as the house rule
 * requires.
 *
 * ⚠ LEAK GUARD. Taxonomy canonical pages render in the `full` view mode. There
 * is no audience-routing hook for this vocabulary yet (that needs a module
 * decision), so EVERY viewer gets `full`. This script therefore REFUSES to
 * proceed if the cloned `full` display contains field_teammate_description —
 * crew training must never render publicly. Staff read the crew copy on the
 * edit form until routing is added.
 *
 * NOT DONE, by instruction ("nothing else"): the vocabulary root listing view,
 * the noindex-until-four-terms rule, Phase 1b (plant problem reference on spray
 * WO bundles), scoping the 35 pickers, and the inbound links from the spray and
 * season pages. Phase 2 (tree spraying consolidation) is roadmap work and is
 * not touched.
 *
 * field_services_performed is left EMPTY on purpose: no `services` term treats
 * Rhizosphaera needle cast — a search for fungicide / disease / needle cast /
 * tree spraying returns zero — and the spec says a problem with no treatment
 * leaves it empty. Todd's decision, 2026-11-03.
 *
 * Images are left empty: the copy specifies four photos and a caption/alt for
 * image 2, but no files were supplied. field_banner_images is multi-value and
 * carries its caption in each image item's own `title` sub-field, which is the
 * house hero-caption standard that `season` already follows.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1. Term created UNPUBLISHED unless
 * BOS_PUBLISH=1.
 *
 * Run: drush php:script web/scripts/create_plant_problems_vocabulary.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityFormDisplay;

const VID = 'plant_problems';
const VID_LABEL = 'Plant Problems';
const SRC_VID = 'season';
const TERM_NAME = 'Spruce Needle Drop';
const ALIAS_PATTERN = '/plant-problems/[term:name]';
const FMT = 'full_html';

// Cloned from season — the approved public-vocabulary field set.
const FIELDS = [
  'field_short_description',
  'field_public_description',
  'field_teammate_description',
  'field_call_to_action',
  'field_services_performed',
  'field_banner_images',
  'field_icon',
  'field_meta_tags',
];
const VIEW_MODES = ['default', 'full', 'teammate_view', 'admin_view'];

$apply = getenv('BOS_APPLY') === '1';
$publish = getenv('BOS_PUBLISH') === '1';
$etm = \Drupal::entityTypeManager();

// ---------------------------------------------------------------------------
// Assert the invariant guard before writing anything.
// ---------------------------------------------------------------------------
$ids = \Drupal::entityQuery('field_config')->accessCheck(FALSE)
  ->condition('field_name', 'field_service')
  ->condition('entity_type', 'work_order')
  ->execute();
$unscoped = [];
foreach ($etm->getStorage('field_config')->loadMultiple($ids) as $fc) {
  $tb = $fc->getSetting('handler_settings')['target_bundles'] ?? NULL;
  if (!$tb || array_keys($tb) !== ['services']) {
    $unscoped[] = $fc->id();
  }
}
printf("invariant check: %d work_order.field_service instances, %d not scoped to `services`\n",
  count($ids), count($unscoped));
if ($unscoped) {
  print "ABORT: these would let a plant problem imply a Work Order type:\n";
  foreach (array_slice($unscoped, 0, 10) as $i) {
    print "  $i\n";
  }
  return;
}

$srcVoc = $etm->getStorage('taxonomy_vocabulary')->load(SRC_VID);
if (!$srcVoc) {
  printf("ABORT: source vocabulary `%s` not found; nothing to clone the pattern from.\n", SRC_VID);
  return;
}

// ---------------------------------------------------------------------------
// Plan
// ---------------------------------------------------------------------------
$voc = $etm->getStorage('taxonomy_vocabulary')->load(VID);
printf("\nvocabulary `%s`: %s\n", VID, $voc ? 'exists' : 'will be created');

$fieldPlan = [];
foreach (FIELDS as $f) {
  $src = FieldConfig::loadByName('taxonomy_term', SRC_VID, $f);
  $dst = FieldConfig::loadByName('taxonomy_term', VID, $f);
  $fieldPlan[$f] = !$src ? 'SOURCE MISSING' : ($dst ? 'exists' : 'clone from ' . SRC_VID);
  printf("  field %-30s %s\n", $f, $fieldPlan[$f]);
}
if (in_array('SOURCE MISSING', $fieldPlan, TRUE)) {
  print "\nABORT: a source field instance is missing; refusing to hand-write a divergent one.\n";
  return;
}

/**
 * Strip components that belong to the SOURCE vocabulary, not to this one.
 *
 * ⚠ Live's season displays embed `season_services_entity_view_1` — the EVA
 * that renders field_services_performed as cards with the sliver image. It is
 * scoped to season terms, so cloning it onto plant_problems would embed a view
 * that does not belong to this vocabulary. (Dev's displays do NOT have it:
 * live view displays are Manage-Display-curated and differ, which is exactly
 * why this is detected rather than assumed.)
 *
 * The cards mechanism needs its own EVA for this vocabulary when a problem
 * actually has a treating service. That is deliberately out of scope here, and
 * moot today because field_services_performed is empty by decision.
 */
$stripSourceSpecific = static function (array $raw, string &$droppedOut = NULL): array {
  $dropped = [];
  foreach (array_keys($raw['content'] ?? []) as $k) {
    if (str_starts_with($k, SRC_VID . '_')) {
      unset($raw['content'][$k]);
      $raw['hidden'][$k] = TRUE;
      $dropped[] = $k;
    }
  }
  $droppedOut = implode(', ', $dropped);
  return $raw;
};

// Leak guard: what will the public view mode actually contain?
$srcFull = $stripSourceSpecific(
  \Drupal::config('core.entity_view_display.taxonomy_term.' . SRC_VID . '.full')->getRawData(),
  $droppedFromFull
);
if ($droppedFromFull) {
  printf("\nwill NOT clone (belongs to `%s`, not this vocabulary): %s\n", SRC_VID, $droppedFromFull);
}
$fullComponents = array_keys($srcFull['content'] ?? []);
printf("\npublic `full` view mode would contain: %s\n", implode(', ', $fullComponents) ?: '(nothing)');
if (in_array('field_teammate_description', $fullComponents, TRUE)) {
  print "ABORT: the cloned `full` display carries field_teammate_description.\n"
    . "Every viewer gets `full` (no routing hook yet), so that would publish crew training.\n";
  return;
}
print "  -> no crew training in the public view mode. OK.\n";

$pattern = $etm->getStorage('pathauto_pattern')->load(VID . '_paths');
printf("\nalias pattern: %s\n", $pattern ? 'exists' : ALIAS_PATTERN . ' (will be created)');
$existingTerm = $voc ? ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => VID, 'name' => TERM_NAME]) ?: NULL) : NULL;
printf("term `%s`: %s\n", TERM_NAME, $existingTerm ? 'exists — will update' : 'will be created');
printf("publish: %s\n", $publish ? 'PUBLISHED' : 'unpublished (BOS_PUBLISH=1 to publish)');

if (!$apply) {
  print "\nDRY RUN — re-run with BOS_APPLY=1.\n";
  return;
}

// ---------------------------------------------------------------------------
// Build
// ---------------------------------------------------------------------------
print "\n";
if (!$voc) {
  Vocabulary::create([
    'vid' => VID,
    'name' => VID_LABEL,
    'description' => 'Plant problems, diseases, environmental damage and false alarms. Reference content for customers and crew — NOT services, and deliberately not referenceable by work_order.field_service.',
    'weight' => 0,
  ])->save();
  print "created vocabulary " . VID . "\n";
}

foreach (FIELDS as $f) {
  if (FieldConfig::loadByName('taxonomy_term', VID, $f)) {
    continue;
  }
  $arr = FieldConfig::loadByName('taxonomy_term', SRC_VID, $f)->toArray();
  unset($arr['uuid'], $arr['id'], $arr['_core'], $arr['dependencies']);
  $arr['bundle'] = VID;
  FieldConfig::create($arr)->save();
  printf("  cloned field instance %s\n", $f);
}

// View displays + the form display, cloned so the new vocabulary looks and
// edits exactly like the approved one.
foreach (VIEW_MODES as $mode) {
  $srcId = 'core.entity_view_display.taxonomy_term.' . SRC_VID . '.' . $mode;
  $raw = \Drupal::config($srcId)->getRawData();
  if (!$raw) {
    printf("  view display %-14s source missing — skipped\n", $mode);
    continue;
  }
  if (EntityViewDisplay::load('taxonomy_term.' . VID . '.' . $mode)) {
    printf("  view display %-14s exists\n", $mode);
    continue;
  }
  $raw = $stripSourceSpecific($raw, $dropped);
  unset($raw['uuid'], $raw['_core'], $raw['dependencies']);
  $raw['id'] = 'taxonomy_term.' . VID . '.' . $mode;
  $raw['bundle'] = VID;
  EntityViewDisplay::create($raw)->save();
  printf("  cloned view display %-14s (%d components)%s\n", $mode, count($raw['content'] ?? []),
    $dropped ? "  [dropped: $dropped]" : '');
}
$rawForm = \Drupal::config('core.entity_form_display.taxonomy_term.' . SRC_VID . '.default')->getRawData();
if ($rawForm && !EntityFormDisplay::load('taxonomy_term.' . VID . '.default')) {
  unset($rawForm['uuid'], $rawForm['_core'], $rawForm['dependencies']);
  $rawForm['id'] = 'taxonomy_term.' . VID . '.default';
  $rawForm['bundle'] = VID;
  EntityFormDisplay::create($rawForm)->save();
  print "  cloned form display default\n";
}

if (!$pattern) {
  $etm->getStorage('pathauto_pattern')->create([
    'id' => VID . '_paths',
    'label' => VID_LABEL . ' paths',
    'type' => 'canonical_entities:taxonomy_term',
    'pattern' => ALIAS_PATTERN,
    'selection_criteria' => [
      [
        'id' => 'entity_bundle:taxonomy_term',
        'negate' => FALSE,
        'context_mapping' => ['taxonomy_term' => 'taxonomy_term'],
        'bundles' => [VID => VID],
        'uuid' => \Drupal::service('uuid')->generate(),
      ],
    ],
    'selection_logic' => 'and',
    'weight' => 0,
    'relationships' => [],
  ])->save();
  print "  created alias pattern " . ALIAS_PATTERN . "\n";
}

// ---------------------------------------------------------------------------
// The one term
// ---------------------------------------------------------------------------
require_once __DIR__ . '/spruce_needle_drop_copy.inc.php';
$copy = _bos_spruce_needle_drop_copy();

$term = $existingTerm ? reset($existingTerm) : Term::create(['vid' => VID, 'name' => TERM_NAME]);
$term->set('field_short_description', ['value' => $copy['card'], 'format' => FMT]);
$term->set('field_public_description', ['value' => $copy['public'], 'format' => FMT]);
$term->set('field_teammate_description', ['value' => $copy['crew'], 'format' => FMT]);
// Empty on purpose — nothing in `services` treats Rhizosphaera needle cast.
$term->set('field_services_performed', []);
$term->set('field_meta_tags', json_encode([
  'title' => $copy['meta_title'],
  'description' => $copy['meta_description'],
  'og_description' => $copy['meta_description'],
]));
// ⚠ NOT setPublished($publish). EntityPublishedInterface::setPublished() lost
// its parameter in Drupal 8.7, so setPublished(FALSE) PUBLISHES — the argument
// is silently ignored. That is exactly what happened on the first live run:
// the term went public when it was meant to be a draft, and only the verify
// block below caught it. Use the explicit pair.
if ($publish) {
  $term->setPublished();
}
else {
  $term->setUnpublished();
}
$term->save();

// ---------------------------------------------------------------------------
// Verify from fresh reads
// ---------------------------------------------------------------------------
$fresh = $etm->getStorage('taxonomy_term')->loadUnchanged($term->id());
printf("\nterm tid %s\n", $fresh->id());
printf("  published:  %s\n", $fresh->isPublished() ? 'YES' : 'no');
printf("  alias:      %s\n", \Drupal::service('path_alias.manager')->getAliasByPath('/taxonomy/term/' . $fresh->id()));
foreach (['field_short_description', 'field_public_description', 'field_teammate_description'] as $f) {
  $v = $fresh->get($f);
  printf("  %-28s %s fmt=%s len=%d\n", $f, $v->isEmpty() ? 'EMPTY' : 'set',
    $v->isEmpty() ? '-' : ($v->format ?? 'n/a'), $v->isEmpty() ? 0 : mb_strlen((string) $v->value));
}
printf("  field_services_performed     %s (expected empty)\n",
  $fresh->get('field_services_performed')->isEmpty() ? 'EMPTY' : '** SET **');
$mt = json_decode((string) ($fresh->get('field_meta_tags')->value ?: ''), TRUE);
printf("  meta tags:  %s\n", is_array($mt) ? implode(', ', array_keys($mt)) : 'UNREADABLE');

// The invariant, restated as a fact rather than an intention.
$can = FALSE;
foreach ($etm->getStorage('field_config')->loadMultiple(
  \Drupal::entityQuery('field_config')->accessCheck(FALSE)
    ->condition('field_name', 'field_service')->condition('entity_type', 'work_order')->execute()
) as $fc) {
  $tb = $fc->getSetting('handler_settings')['target_bundles'] ?? [];
  if (isset($tb[VID])) {
    $can = TRUE;
  }
}
printf("\nwork_order.field_service can reference plant_problems: %s\n",
  $can ? '** YES — INVARIANT BREACHED **' : 'NO (requirement 1 holds)');
