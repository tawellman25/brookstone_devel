<?php

/**
 * @file
 * The Spruce Needle Drop copy, verbatim from marketing.
 *
 * Kept in its own include so the build script stays readable and so the copy
 * can be re-applied without re-running the vocabulary build. Nothing here is
 * authored or edited by Code — it is transcribed from
 * "Spruce Needle Drop Page Copy.md" (revised 2026-11-03).
 *
 * The subtitle line in that file is deliberately NOT here: it applies only if
 * the container has a `field_subtitle`, and `plant_problems` does not (it
 * clones the `season` field set). The file says to drop it in that case.
 */

/**
 * The finished copy, keyed by the field it maps to.
 */
function _bos_spruce_needle_drop_copy(): array {
  $card = 'A spruce that browns through the middle in fall is usually shedding its oldest needles. Where the brown sits is what tells you whether to worry.';

  $public = <<<'HTML'
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

  $crew = <<<'HTML'
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

  return [
    'card' => $card,
    'public' => $public,
    'crew' => $crew,
    'meta_title' => 'Why Is My Spruce Turning Brown? | Brookstone',
    'meta_description' => 'Brown needles through the middle of a spruce in fall are usually normal shed. Where the brown sits tells you whether to worry, and the check that confirms it.',
    // For when the four photos arrive. field_banner_images is multi-value and
    // the caption belongs in the image item's own `title` sub-field, which is
    // the house standard that `season` already follows — not in alt.
    'image_2_title' => 'The diagnosis is in where the brown sits. Dead needles on the oldest wood closest to the trunk, healthy blue-green growth on every tip. A tree in trouble browns the other way round.',
    'image_2_alt' => 'Blue spruce branch with brown needles on inner old wood and healthy green growth at the branch tips.',
  ];
}
