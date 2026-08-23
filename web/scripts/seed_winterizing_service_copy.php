<?php

/**
 * P0.4 — seed the public winterizing copy onto services term 369.
 *
 * Taxonomy terms are CONTENT (they don't travel on config export), so this runs
 * per environment, like seed_service_request_status.php. Idempotent, and it will
 * NOT overwrite a non-empty field unless --force is passed (the office may have
 * edited on live). Body is plain <h3>/<p> — the /winterize template builds the
 * accordions, so there is no <details> in stored content and no format trap.
 *
 *   ddev drush php:script web/scripts/seed_winterizing_service_copy.php
 *   ddev drush php:script web/scripts/seed_winterizing_service_copy.php -- --force
 *   (SEED_FORCE=1 env var also forces.)
 */

use Drupal\taxonomy\Entity\Term;

$force = in_array('--force', (array) ($argv ?? []), TRUE) || (bool) getenv('SEED_FORCE');
$TID = 369;

$subtitle = 'Protecting your sprinkler system from winter freeze damage — Delta and Montrose counties, over 30 years.';

$summary = <<<'HTML'
<p>Water left in your sprinkler lines expands when it freezes and splits whatever it is sitting in — pipe, valves, backflow, pump. Underground breaks stay hidden until spring, and by then you are digging.</p>

<p>Winterizing is the least expensive service we perform and it prevents the most expensive repairs. Over 30 years on the Western Slope, and we guarantee our work: if anything freezes after we have winterized it and you let us make the repair, we repair it at no charge.</p>
HTML;

$body = <<<'HTML'
<p>You have a lot of money in the ground. When water freezes it expands, and it does not care what it is expanding inside of — PVC pipe, poly line, valves, backflow assemblies, pumps. The only way to protect a sprinkler system through a Western Slope winter is to get the water out of it and keep it out until spring.</p>

<h3>What freezing actually does to a system</h3>

<p>Frozen water shatters PVC pipe — not cracks it, shatters it, into slivers. Poly pipe holds up better but still splits. The expensive part is that underground breaks are invisible. You do not find out in December; you find out in April when you turn the water on and it either never reaches the surface or shows up somewhere it should not. We have seen freeze breaks run straight into the ground for weeks, and the homeowner found out from the water bill.</p>

<p>Above-ground parts at least announce themselves. A cracked backflow or pressure vacuum breaker will throw water several feet in the air, usually first thing in the morning, usually on a day you had other plans.</p>

<h3>How we blow out your system</h3>

<p>We run an 85 CFM diesel compressor — high volume, not just high pressure. Volume is what matters. We connect as close to the water source as the system allows and work through every zone for a set time. The volume of air creates turbulence inside the pipe, which pulls water out of the low spots and off the sidewalls where a smaller compressor leaves it sitting.</p>

<p>Then we do it again. After the first pass we let the system settle, then run every zone a second time — water that was clinging to the walls collects in the low spots while it sits, and the second pass takes it out. That second pass is the difference between a system that is mostly dry and one that is dry. It is why we are comfortable guaranteeing the work.</p>

<h3>Do you guarantee it?</h3>

<p>Yes. If any part of your system suffers freeze damage after we have winterized it, and you allow us to make the repair, we repair it at no charge.</p>

<h3>Do I need to be home?</h3>

<p>Usually not. Most of the systems we service shut off from outside the foundation — we set them up that way on purpose, so we are not tracking through your house in work boots to reach a valve. If we can get to your shutoff, backflow and controller, you do not need to be here.</p>

<p>If your shutoff is inside the house, we will need to arrange a time. We know which properties those are, and we will call you.</p>

<p>Either way, tell us about gates, dogs, or anything locked when you sign up.</p>

<h3>Cover your backflow until we get there</h3>

<p>Routes take us through October, and an early cold snap does not wait for your turn. If a hard freeze is possible before your scheduled week, cover your backflow assembly or pump — a bucket, a blanket, some insulation is usually enough. We are not responsible for freeze damage that occurs before we winterize your system.</p>

<h3>Turning it back on in the spring</h3>

<p>Have us do it. A spring start-up is not just opening a valve — it is the first real look at whether the system came through the winter. We know how a system is supposed to behave when it comes up to pressure, and we know what it looks like when something is wrong. If there is a break, we find it that day and fix it, instead of you finding it in June by way of a brown patch or a water bill.</p>

<p>We start turning systems on once the hard morning freezes are behind us. Above-ground parts — pressure vacuum breakers especially — are the vulnerable ones, and an early start-up puts them at risk for no good reason.</p>

<h3>Adjust your watering this fall</h3>

<p>As the weather cools, turf and plants need noticeably less water. Cutting run times back through September and October lowers your water bill and does your lawn no harm at all. It is the easiest money you will save all season.</p>

<p>Thank you for choosing Brookstone Outdoors.</p>
HTML;

$term = Term::load($TID);
if (!$term || $term->bundle() !== 'services') {
  echo "ERROR: services term $TID not found.\n";
  return;
}

$out = [];

// field_subtitle (plain string).
if ($term->hasField('field_subtitle')) {
  if ($force || $term->get('field_subtitle')->isEmpty()) {
    $term->set('field_subtitle', $subtitle);
    $out[] = 'field_subtitle: written';
  }
  else {
    $out[] = 'field_subtitle: SKIPPED (non-empty; pass --force to overwrite)';
  }
}

// field_service_public_desc (text_with_summary: body + summary).
if ($term->hasField('field_service_public_desc')) {
  if ($force || $term->get('field_service_public_desc')->isEmpty()) {
    $term->set('field_service_public_desc', ['value' => $body, 'summary' => $summary, 'format' => 'full_html']);
    $out[] = 'field_service_public_desc: written (body + summary)';
  }
  else {
    $out[] = 'field_service_public_desc: SKIPPED (non-empty; pass --force to overwrite)';
  }
}

$term->save();

echo "== seed_winterizing_service_copy (term $TID)" . ($force ? ' [--force]' : '') . " ==\n";
foreach ($out as $l) {
  echo "  - $l\n";
}
