<?php

/**
 * @file
 * Plant Problems landing page at /material/plants/problems — idempotent setup.
 *
 *   ddev drush php:script web/scripts/plant_problems_landing_setup.php
 *   (live) drush php:script web/scripts/plant_problems_landing_setup.php
 *
 * Marketing brief 2026-11-09 ("Plant Problems landing page — build prompt"),
 * checked against the house rules first. The brief assumed "nothing new is
 * needed except the term and the copy" — not quite: the /material/plants grid
 * (material_children) lists the Plants term's children IN material_types, while
 * the problem pages are terms of the separate plant_problems vocabulary (kept
 * separate on purpose on 2026-11-03 so an article never implies a Work Order
 * type). So the landing term lives in material_types — which is what puts its
 * card on /material/plants and gives the leaf pages a parent chain to walk —
 * and it DECLARES which other vocabulary supplies its child cards.
 *
 * What this script does, in order, each step skipped when already present:
 *   1. field_child_vocabulary on taxonomy_term.material_types — entity
 *      reference to a vocabulary. Set on a category whose children live in
 *      another vocabulary; empty everywhere else. Created from PHP rather than
 *      cim because of the BOS field-instance silent-skip bug
 *      (Governance/working_with_claude.md). Export the YAML afterwards.
 *   2. Its widget on the material_types term form, beside field_material_bundle.
 *   3. The plant_problem_children view, imported from config/sync. EVA on
 *      material_types; argument = [term:field_child_vocabulary:target_id] →
 *      "Taxonomy term: Vocabulary", hidden when the token is empty. A category
 *      with no child vocabulary renders nothing; no path is hardcoded anywhere.
 *   4. The EVA placed on every material_types view display, after the other
 *      three EVAs and before the call-to-action — the order /material/plants has.
 *      ⚠ Live displays are Manage-Display-curated: this ADDS one component and
 *      touches nothing else.
 *   5. The "Plant Problems" term under Plants (weight 10 — last; the eight
 *      plant-type cards sit at −10..0), copy per the brief, meta tags, manual
 *      alias /material/plants/problems (material_types has no pathauto
 *      pattern; aliases in this tree are set by hand), published.
 *      field_material_bundle is deliberately EMPTY so material_type_items hides
 *      itself instead of printing "No materials found for this type."
 *   6. xmlsitemap inclusion for plant_problems terms, matching material_types.
 *      The Phase-1 "noindex until four terms" rule was withdrawn by marketing.
 *
 * NOT done here, by design: field_short_description is never put on a view
 * display (the model forbids it; bos_content_coverage would strip it); the leaf
 * alias pattern is untouched; the breadcrumb fix is code (bos_breadcrumbs).
 */

declare(strict_types=1);

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\pathauto\PathautoState;
use Drupal\taxonomy\Entity\Term;
use Drupal\views\Entity\View;
use Symfony\Component\Yaml\Yaml;

const PPL_VID = 'material_types';
const PPL_CHILD_VID = 'plant_problems';
const PPL_FIELD = 'field_child_vocabulary';
const PPL_VIEW = 'plant_problem_children';
const PPL_EVA_COMPONENT = PPL_VIEW . '_entity_view_1';
const PPL_PARENT_ALIAS = '/material/plants';
const PPL_ALIAS = '/material/plants/problems';
const PPL_NAME = 'Plant Problems';
const PPL_WEIGHT = 10;

// ---- Copy, verbatim from the brief. -------------------------------------
const PPL_TITLE_TAG = 'Plant Problems in Delta & Montrose CO | Brookstone';
const PPL_META_DESCRIPTION = 'Plant problems in Delta and Montrose counties: what it is, whether it is worth treating, and when the right answer is that nothing is wrong with the plant.';
const PPL_CARD_SUMMARY = 'Why a plant on your property looks the way it does — what it is, whether it is worth treating, and when the right answer is to leave it alone.';
const PPL_BODY = <<<'HTML'
<p>Every fall the phone starts ringing about spruce trees going brown through the middle, and most of the time nothing is wrong with the tree. That conversation happens enough times in a season that it was worth writing down. These pages are where those answers go.</p>

<p>Each one covers a single problem: what it looks like, how to tell it apart from the things it gets mistaken for, whether it is worth treating, and what treating it actually involves. Where there is a treatment we offer, the page says so. Where the honest answer is to leave the plant alone, or to take it out and replant, the page says that instead. A plant that is doing something alarming and perfectly normal is the most common case we see, and it is the cheapest problem anybody ever has.</p>

<p>Plants struggle here for reasons particular to this ground. Alkaline soil locks up iron that is physically present and still unavailable to the roots. Evergreens lose water through their needles all winter while their roots sit in frozen ground. A late frost arrives after everything has broken bud. Winter sun on a south or west face does damage that nothing on the north side of the same plant shows. And the dates all move with elevation, so a yard at six thousand feet is running a different calendar than one on the valley floor. A national fact sheet will name a pest correctly and tell you nothing useful about when it shows up here.</p>

<p>These pages are written from what the crews actually see on properties in Delta and Montrose counties, and the list grows as the questions come in. If you are looking at something on your own property and cannot tell which of these it is, <a href="/request-estimate">request an estimate</a> and somebody will come out and look at it.</p>
HTML;

$etm = \Drupal::entityTypeManager();
$am = \Drupal::service('path_alias.manager');
$out = static fn(string $s) => print($s . "\n");

// ---- Preconditions -------------------------------------------------------
$parentPath = $am->getPathByAlias(PPL_PARENT_ALIAS);
if (!preg_match('#^/taxonomy/term/(\d+)$#', $parentPath, $m)) {
  throw new \RuntimeException(PPL_PARENT_ALIAS . ' does not resolve to a term (' . $parentPath . ').');
}
$plants = Term::load((int) $m[1]);
if (!$plants || $plants->bundle() !== PPL_VID) {
  throw new \RuntimeException('Plants term not found / wrong vocabulary.');
}
if (!$etm->getStorage('taxonomy_vocabulary')->load(PPL_CHILD_VID)) {
  throw new \RuntimeException('Vocabulary ' . PPL_CHILD_VID . ' does not exist — Phase 1 missing.');
}
if (!\Drupal::moduleHandler()->moduleExists('eva')) {
  throw new \RuntimeException('eva is not enabled.');
}
$out(sprintf('Plants = tid %d (%s)', $plants->id(), PPL_PARENT_ALIAS));

// ---- 1. Field storage + instance ---------------------------------------
$storage = FieldStorageConfig::loadByName('taxonomy_term', PPL_FIELD);
if (!$storage) {
  $storage = FieldStorageConfig::create([
    'field_name' => PPL_FIELD,
    'entity_type' => 'taxonomy_term',
    'type' => 'entity_reference',
    'cardinality' => 1,
    'settings' => ['target_type' => 'taxonomy_vocabulary'],
  ]);
  $storage->save();
  $out('1. created field storage taxonomy_term.' . PPL_FIELD);
}
else {
  $out('1. field storage exists');
}
$instance = FieldConfig::loadByName('taxonomy_term', PPL_VID, PPL_FIELD);
if (!$instance) {
  FieldConfig::create([
    'field_storage' => $storage,
    'bundle' => PPL_VID,
    'label' => 'Child vocabulary',
    'description' => 'Only for a category whose child pages live in ANOTHER vocabulary (e.g. Plant Problems → plant_problems): the published terms of that vocabulary render as this category\'s child cards. Leave empty for ordinary categories — their children are the material_types terms beneath them.',
    'required' => FALSE,
    'settings' => [
      'handler' => 'default:taxonomy_vocabulary',
      'handler_settings' => ['target_bundles' => NULL, 'auto_create' => FALSE],
    ],
  ])->save();
  $out('   created field instance on ' . PPL_VID);
}
else {
  $out('   field instance exists');
}

// ---- 2. Form widget ------------------------------------------------------
$form = EntityFormDisplay::load('taxonomy_term.' . PPL_VID . '.default');
if ($form && !$form->getComponent(PPL_FIELD)) {
  $form->setComponent(PPL_FIELD, ['type' => 'options_select', 'weight' => 5, 'region' => 'content', 'settings' => [], 'third_party_settings' => []])->save();
  $out('2. widget added to the material_types term form');
}
else {
  $out('2. widget ' . ($form ? 'already on the form' : 'SKIPPED — no default form display'));
}

// ---- 3. The view ---------------------------------------------------------
if (!View::load(PPL_VIEW)) {
  $yaml = DRUPAL_ROOT . '/../config/sync/views.view.' . PPL_VIEW . '.yml';
  if (!is_file($yaml)) {
    throw new \RuntimeException('Missing ' . $yaml);
  }
  $raw = Yaml::parseFile($yaml);
  unset($raw['uuid'], $raw['_core']);
  View::create($raw)->save();
  $out('3. view ' . PPL_VIEW . ' imported from config/sync');
}
else {
  $out('3. view exists');
}

// ---- 4. EVA on every material_types view display -----------------------
// ⚠ EVA places its component itself when the view is saved (eva_view_presave),
// at a fixed weight 10 on every display of the bundle — which on admin_view
// (CTA at 5) lands AFTER the call-to-action. So this step does not add; it
// POSITIONS: directly after the highest existing EVA and strictly before
// field_call_to_action, per display, read not assumed. Nothing else moves.
foreach ($etm->getStorage('entity_view_display')->loadByProperties(['targetEntityType' => 'taxonomy_term', 'bundle' => PPL_VID]) as $display) {
  /** @var \Drupal\Core\Entity\Entity\EntityViewDisplay $display */
  $evaWeights = [];
  foreach ($display->getComponents() as $name => $c) {
    if ($name !== PPL_EVA_COMPONENT && str_ends_with($name, '_entity_view_1')) {
      $evaWeights[] = (int) ($c['weight'] ?? 0);
    }
  }
  $maxEva = $evaWeights ? max($evaWeights) : 9;
  $weight = $maxEva + 1;
  $cta = $display->getComponent('field_call_to_action');
  if ($cta && (int) $cta['weight'] <= $weight) {
    // Strictly below the CTA. If that ties an existing EVA, insertion order
    // decides and this component is the newest, so it still renders after them.
    $weight = max((int) $cta['weight'] - 1, $maxEva);
  }
  $current = $display->getComponent(PPL_EVA_COMPONENT);
  if ($current && (int) $current['weight'] === $weight) {
    $out(sprintf('4. %s: EVA already at weight %d', $display->getMode(), $weight));
    continue;
  }
  $display->setComponent(PPL_EVA_COMPONENT, ['weight' => $weight, 'region' => 'content', 'settings' => [], 'third_party_settings' => []])->save();
  $out(sprintf('4. %s: EVA %s at weight %d', $display->getMode(), $current ? 're-weighted' : 'placed', $weight));
}

// ---- 5. The term ---------------------------------------------------------
$existing = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => PPL_VID, 'name' => PPL_NAME]);
$term = NULL;
foreach ($existing as $cand) {
  foreach ($etm->getStorage('taxonomy_term')->loadParents($cand->id()) as $p) {
    if ((int) $p->id() === (int) $plants->id()) {
      $term = $cand;
    }
  }
}
$metaTags = json_encode([
  'title' => PPL_TITLE_TAG,
  'description' => PPL_META_DESCRIPTION,
  'og_description' => PPL_META_DESCRIPTION,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!$term) {
  $term = Term::create([
    'vid' => PPL_VID,
    'name' => PPL_NAME,
    'parent' => [$plants->id()],
    'weight' => PPL_WEIGHT,
    'field_public_description' => ['value' => PPL_BODY, 'format' => 'full_html'],
    'field_short_description' => ['value' => PPL_CARD_SUMMARY, 'format' => 'full_html'],
    'field_meta_tags' => ['value' => $metaTags],
    PPL_FIELD => ['target_id' => PPL_CHILD_VID],
  ]);
  $term->setPublished();
  $term->get('path')->alias = PPL_ALIAS;
  $term->get('path')->pathauto = PathautoState::SKIP;
  $term->save();
  $out(sprintf('5. created term %d "%s" under Plants, weight %d', $term->id(), PPL_NAME, PPL_WEIGHT));
}
else {
  $out(sprintf('5. term exists: tid %d', $term->id()));
}

// ---- 6. Sitemap ----------------------------------------------------------
$sm = \Drupal::configFactory()->getEditable('xmlsitemap.settings.taxonomy_term.' . PPL_CHILD_VID);
if ($sm->isNew() || $sm->get('status') === NULL) {
  $ref = \Drupal::config('xmlsitemap.settings.taxonomy_term.' . PPL_VID)->getRawData();
  $sm->setData(['status' => TRUE, 'priority' => $ref['priority'] ?? 0.5, 'changefreq' => $ref['changefreq'] ?? 2419200])->save();
  $out('6. xmlsitemap: plant_problems terms now included (' . json_encode($sm->getRawData()) . ')');
}
else {
  $out('6. xmlsitemap: plant_problems setting already present ' . json_encode($sm->getRawData()));
}

// ---- Verify (fresh loads) -----------------------------------------------
\Drupal::entityTypeManager()->getStorage('taxonomy_term')->resetCache([$term->id()]);
$fresh = Term::load($term->id());
$out('--- verify');
$out(sprintf('  term %d published=%d weight=%d parent=%s', $fresh->id(), (int) $fresh->isPublished(), $fresh->getWeight(), implode(',', array_keys($etm->getStorage('taxonomy_term')->loadParents($fresh->id())))));
$out('  alias: ' . $am->getAliasByPath('/taxonomy/term/' . $fresh->id()) . '  (resolves back to ' . $am->getPathByAlias(PPL_ALIAS) . ')');
$out('  child vocabulary: ' . ($fresh->get(PPL_FIELD)->target_id ?? '(empty)') . '  token → [' . \Drupal::token()->replace('[term:' . PPL_FIELD . ':target_id]', ['term' => $fresh], ['clear' => TRUE]) . ']');
$out('  material bundle: ' . ($fresh->get('field_material_bundle')->value ?? '') . '(empty expected)');
$out('  meta tags: ' . $fresh->get('field_meta_tags')->value);
$out('  body words: ' . str_word_count(strip_tags((string) $fresh->get('field_public_description')->value)) . '  teaser chars: ' . mb_strlen((string) $fresh->get('field_short_description')->value));
$view = \Drupal\views\Views::getView(PPL_VIEW);
$view->setDisplay('entity_view_1');
$view->setArguments([PPL_CHILD_VID]);
$view->execute();
$out('  view rows for ' . PPL_CHILD_VID . ': ' . count($view->result) . ' → ' . implode(' | ', array_map(fn($r) => $r->_entity->label(), $view->result)));
foreach ($etm->getStorage('entity_view_display')->loadByProperties(['targetEntityType' => 'taxonomy_term', 'bundle' => PPL_VID]) as $d) {
  $c = $d->getComponents();
  uasort($c, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
  $out(sprintf('  display %s: %s', $d->getMode(), implode(' → ', array_keys($c))));
}
$short = array_filter($etm->getStorage('entity_view_display')->loadByProperties(['targetEntityType' => 'taxonomy_term']), fn($d) => $d->getComponent('field_short_description'));
$out('  taxonomy displays carrying field_short_description (must be 0): ' . count($short));
