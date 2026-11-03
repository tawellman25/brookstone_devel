<?php

/**
 * @file
 * Create the "Spruce Needle Drop" plant-health page in the `services` vocab.
 *
 * Content supplied as finished copy (Spruce_Needle_Drop_Page_Copy.md, written
 * 2026-11-03). This script only places it; it invents no wording.
 *
 * ⚠ MIRRORS Pine Needle Scale (tid 402), which is the right template and not
 * an arbitrary one: it is the only other child of Spraying that is a
 * PLANT-HEALTH page rather than a bookable service —
 * `field_work_order_service = FALSE` with `field_service_bundle` empty.
 *
 * That flag is the one thing here that could actually break something. The BOS
 * invariant is `work_order.bundle == field_service.field_service_bundle`; a
 * services term flagged as a work-order service with no bundle behind it would
 * offer itself for WO creation and produce a work order that cannot resolve its
 * own bundle. So: FALSE, bundle empty, deliberately.
 *
 * Field mapping follows the three-field content model, which the copy brief
 * happens to agree with (checked against the live field list rather than
 * assumed from the brief):
 *   - Card summary          -> field_short_description  (VIEWS-ONLY: the card
 *                              text on the PARENT term's page. It is NOT on any
 *                              view mode, and bos_content_coverage actively
 *                              strips it from one. Do not "fix" that.)
 *   - Public article        -> field_service_public_desc  (text_with_summary;
 *                              summary left empty on purpose — the card text
 *                              lives in field_short_description, and putting it
 *                              in both is how the two drift apart)
 *   - Crew training         -> field_service_crew_desc    (never on a public
 *                              display)
 *   - Static hero line      -> field_subtitle (255 max; this copy is 86)
 * All three text fields are full_html, matching the siblings.
 *
 * NOT SET, and each for a reason rather than an oversight:
 *   - field_banner_image / field_iconic_image — the brief specifies four photos
 *     and a caption/alt for image 2, but no image files were supplied. Both
 *     siblings are also imageless, so this is consistent, not broken. Note
 *     `bos_services` is PRE-STANDARD for hero captions (it uses the static
 *     field_subtitle, not the per-photo image `title` sub-field), so when the
 *     photos do land, the image-2 caption belongs in the image item's `title`
 *     only if this bundle is migrated to that standard first.
 *   - field_sop_code — siblings carry one (Pine Needle Scale = "PNS"). Service
 *     codes are identifiers; this script will not invent one. Left empty to be
 *     assigned deliberately.
 *
 * Idempotent: re-running updates the existing term rather than creating a
 * second one. DRY-RUN unless BOS_APPLY=1. Created UNPUBLISHED unless
 * BOS_PUBLISH=1.
 *
 * Run: drush php:script web/scripts/create_spruce_needle_drop_term.php
 */

const VID = 'services';
const TERM_NAME = 'Spruce Needle Drop';
const PARENT_TID = 366;        // Spraying
const DEPARTMENT_TID = 3;      // mirrors Pine Needle Scale
const LIST_ORDER = 6;
const FMT = 'full_html';

$SUBTITLE = 'Brown needles through the middle of a spruce in fall are usually the tree doing its job';

$CARD = 'A spruce that browns through the middle in fall is usually shedding its oldest needles. Where the brown sits is what tells you whether to worry.';

$META_TITLE = 'Why Is My Spruce Turning Brown? | Brookstone';
$META_DESC = 'Brown needles through the middle of a spruce in fall are usually normal shed. Where the brown sits tells you whether to worry, and the check that confirms it.';

$PUBLIC = <<<'HTML'
<p>Every October the phone starts ringing about spruce. A tree that looked fine all summer is suddenly brown through the middle, the owner has decided it is dying, and quite often nothing is wrong with it at all.</p>

<p><strong>The answer is in where the brown is.</strong></p>

<p>Pick a branch and follow it from the trunk outward. On a healthy spruce shedding its old needles, the brown sits on the oldest wood close to the trunk, and every branch tip is green and growing. That is a spruce doing exactly what spruce do. A needle lasts several years and then the tree lets the oldest ones go, usually in fall, all through the interior at once. It can look dramatic from six feet away and mean nothing.</p>

<p>Evergreen never meant every needle is permanent. It means the tree is never bare.</p>

<p><strong>The pattern that matters is the reverse one.</strong> Brown starting at the branch tips and working inward is a tree under stress — short of water, root trouble, or winter damage. Browning on one side of the tree while the rest stays green is usually exposure, the south and west faces taking winter sun and wind. A whole branch dead while the branches above and below it are fine points at something in that limb rather than something general. None of those are shedding, and all of them are worth looking at.</p>

<p>Timing is the other half of it. Interior needles going brown in September and October is the normal cycle. The same tree going brown in March is a different story — that is winter burn, caused by an evergreen losing water through its foliage all winter with its roots locked in frozen ground, and it is the reason a deep soak before the ground freezes is the most useful thing anybody does for a spruce here.</p>

<p><strong>If you want to be certain, the check takes two minutes.</strong> Pull a few of the brown needles off and look at them closely, with a magnifier if you have one. A healthy needle has neat rows of small white dots running along its length. If those rows have been replaced by tiny black specks, as though someone shook pepper down the needle, that is a fungus called Rhizosphaera needle cast rather than normal shed. It is common on Colorado blue spruce, it works from the lower branches upward over several years, and it is worth catching early.</p>

<p><strong>We do treat it.</strong> The spray is protective rather than curative, which shapes everything about how it works. It shields the new needles as they come out, so it goes on when the new growth is roughly half extended rather than on a fixed date, and again three or four weeks later. It also means the tree does not improve quickly — the needles already carrying the fungus will brown and drop on their own schedule regardless, so it usually takes two or three consecutive years before a tree reads as healthy again. We would rather say that at the start than have you wondering in year two. All applications are made under Brookstone Outdoors' Colorado Department of Agriculture commercial applicator license.</p>

<p>And if a tree has already thinned from the bottom up far enough that three years will not bring it back, we will tell you. Replacing it is sometimes the better money.</p>

<p>Short of that, a spruce with brown through the middle and green on every tip in fall is a tree that is fine. Leave it alone.</p>
HTML;

$CREW = <<<'HTML'
<p><strong>This is one of the most common calls we take in fall and most of the time the right answer is that nothing is wrong.</strong> Being able to say that confidently, on the spot, with a reason, is worth more to a customer than a quote.</p>

<h3>The diagnostic, in order</h3>

<ul>
<li><strong>Where is the brown?</strong> Interior, on old wood, with green tips → normal seasonal shed. This is the common one.</li>
<li><strong>Tips browning and working inward</strong> → stress. Water first, then look at the root zone, planting depth and recent trenching.</li>
<li><strong>One side only, usually south or west</strong> → winter burn or wind exposure. Shows in late winter and early spring, not fall.</li>
<li><strong>Whole branch dead, branches above and below healthy</strong> → something in that limb. Look for a canker, a wound or sunken dead bark, and resin bleeding. Do not call this shed.</li>
<li><strong>Lower branches thinning year on year, working upward</strong> → needle cast. Different problem, longer timeline, and the one worth catching.</li>
</ul>

<h3>The needle check</h3>

<p>Carry a hand lens. Pull brown needles and look at the rows of white stomatal dots along the length. Clean white rows means normal shed. Rows of tiny black specks means Rhizosphaera. <strong>This is the single check that turns an opinion into a diagnosis</strong>, and it takes two minutes on site.</p>

<h3>If it is needle cast</h3>

<ul>
<li><strong>Catching it early is the whole game.</strong> A tree flagged in its first or second year of symptoms comes back. One that has lost its bottom third is a longer program with a less certain ending.</li>
<li><strong>Timing is growth stage, not calendar.</strong> New needles about half extended, then again three to four weeks later. A fixed date will be wrong in a warm spring and wrong again in a late one.</li>
<li><strong>Sell the three years at the first visit.</strong> A customer who hears it up front stays with the program. One who hears it in year two concludes it did not work.</li>
<li><strong>Know when to say no.</strong> A spruce thinned out past recovery is a removal and replant conversation, not a spray program. Saying so costs one job and keeps the customer.</li>
</ul>

<h3>Season matters as much as pattern</h3>

<p>Interior browning in September and October is the cycle. The same appearance in March is winter burn, and the conversation is about the fall watering that did not happen rather than about the tree. Do not give the fall answer to a spring question.</p>

<h3>What to tell a customer</h3>

<p><strong>"Look where the brown is — it is all on the inside, and every tip is green. That is the tree dropping needles it is finished with. If it were dying it would go the other way, from the tips in."</strong></p>

<p>Then show them. Hold a branch and walk their eye from trunk to tip. People believe what they are shown far more than what they are told, and a customer who has been shown this once stops worrying about it every October.</p>

<h3>On the ticket</h3>

<ul>
<li><strong>Note it even when it is nothing.</strong> Property record, species, what you saw, what you told them. Next October somebody else takes that call and the history answers it.</li>
<li><strong>Flag needle cast or canker upward the same day.</strong> Those have a timeline and they do not improve on their own.</li>
<li><strong>Deep water before freeze goes on the ticket for every spruce on the property</strong>, regardless of why you were there. It prevents the March call.</li>
</ul>

<p><strong>And do not sell a treatment for normal needle drop.</strong> It is the fastest way to lose a customer who later finds out, and telling somebody their tree is fine is the cheapest trust we ever buy.</p>
HTML;

$apply = getenv('BOS_APPLY') === '1';
$publish = getenv('BOS_PUBLISH') === '1';
$etm = \Drupal::entityTypeManager();
$ts = $etm->getStorage('taxonomy_term');

// --- preflight ---------------------------------------------------------------
$parent = $ts->load(PARENT_TID);
if (!$parent || $parent->bundle() !== VID) {
  printf("ABORT: parent term %d not found in the %s vocabulary.\n", PARENT_TID, VID);
  return;
}
if (mb_strlen($SUBTITLE) > 255) {
  printf("ABORT: subtitle is %d chars; field_subtitle holds 255.\n", mb_strlen($SUBTITLE));
  return;
}
$formats = $etm->getStorage('filter_format')->loadMultiple();
if (!isset($formats[FMT])) {
  printf("ABORT: text format '%s' does not exist here.\n", FMT);
  return;
}

$existing = $ts->loadByProperties(['vid' => VID, 'name' => TERM_NAME]);
$term = reset($existing) ?: NULL;

printf("parent:      %s (%d)\n", $parent->label(), PARENT_TID);
printf("term:        %s\n", $term ? 'EXISTS (tid ' . $term->id() . ') — will update' : 'new');
printf("publish:     %s\n", $publish ? 'PUBLISHED' : 'unpublished (set BOS_PUBLISH=1 to publish)');
printf("subtitle:    %d chars\n", mb_strlen($SUBTITLE));
printf("card:        %d chars -> field_short_description (views-only)\n", mb_strlen($CARD));
printf("public:      %d chars -> field_service_public_desc (%s)\n", mb_strlen($PUBLIC), FMT);
printf("crew:        %d chars -> field_service_crew_desc (%s)\n", mb_strlen($CREW), FMT);
printf("wo service:  FALSE, bundle empty  (plant-health page, mirrors Pine Needle Scale)\n");

if (!$apply) {
  print "\nDRY RUN — re-run with BOS_APPLY=1.\n";
  return;
}

if (!$term) {
  $term = $ts->create(['vid' => VID, 'name' => TERM_NAME]);
}
$term->set('parent', [PARENT_TID]);
$term->set('field_parent_service', ['target_id' => PARENT_TID]);
$term->set('field_service_name', TERM_NAME);
$term->set('field_department', ['target_id' => DEPARTMENT_TID]);
$term->set('field_list_order', LIST_ORDER);
$term->set('field_subtitle', $SUBTITLE);
$term->set('field_short_description', ['value' => $CARD, 'format' => FMT]);
$term->set('field_service_public_desc', ['value' => $PUBLIC, 'summary' => '', 'format' => FMT]);
$term->set('field_service_crew_desc', ['value' => $CREW, 'format' => FMT]);
// The invariant guard: not a bookable work-order service.
$term->set('field_work_order_service', 0);
$term->set('field_service_bundle', NULL);
$term->set('field_estimate_service', 0);
$term->set('field_parent_component', 0);
$term->set('field_home_page', 0);
// ⚠ The metatag field stores JSON, not PHP-serialized data. A serialize() call
// here happened to survive because the field normalizes on save, but write what
// is actually stored. `og_description` mirrors Pine Needle Scale, which carries
// it so a shared link has a description of its own rather than falling back.
$term->set('field_meta_tags', json_encode([
  'title' => $META_TITLE,
  'description' => $META_DESC,
  'og_description' => $META_DESC,
]));
$term->setPublished($publish);
$term->save();

// --- verify from a fresh load ------------------------------------------------
$fresh = $ts->loadUnchanged($term->id());
$alias = \Drupal::service('path_alias.manager')->getAliasByPath('/taxonomy/term/' . $fresh->id());
printf("\nsaved tid %s\n", $fresh->id());
printf("  published:          %s\n", $fresh->isPublished() ? 'YES' : 'no');
printf("  alias:              %s\n", $alias);
printf("  parent:             %s\n", implode(',', array_map(fn($p) => $p->label(), $ts->loadParents($fresh->id()))));
printf("  work_order_service: %s   bundle: %s\n",
  $fresh->get('field_work_order_service')->value ? '** TRUE — WRONG **' : 'FALSE',
  $fresh->get('field_service_bundle')->value ?: '(empty)');
foreach (['field_subtitle', 'field_short_description', 'field_service_public_desc', 'field_service_crew_desc'] as $f) {
  $v = $fresh->get($f);
  printf("  %-28s %s  fmt=%s  len=%d\n", $f, $v->isEmpty() ? 'EMPTY' : 'set',
    $v->isEmpty() ? '-' : ($v->format ?? 'n/a'), $v->isEmpty() ? 0 : mb_strlen((string) $v->value));
}
// JSON, not serialize() — reading it the wrong way is what made a correctly
// saved title look like it had not saved at all.
$mt = json_decode((string) ($fresh->get('field_meta_tags')->value ?: ''), TRUE);
printf("  meta tags:          %s\n", is_array($mt) ? implode(', ', array_keys($mt)) : 'UNREADABLE');
printf("  meta title:         %s\n", is_array($mt) ? ($mt['title'] ?? '-') : '-');
print "\nNOT set (see the file header): field_banner_image, field_iconic_image (no files supplied),\n"
  . "field_sop_code (identifiers are not invented here).\n";
