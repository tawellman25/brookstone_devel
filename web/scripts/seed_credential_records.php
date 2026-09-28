<?php

declare(strict_types=1);

/**
 * Seed the eight credential records behind /about-us/credentials, with their
 * field_public_description copy (verbatim from the 2026-09-28 spec).
 *
 * PLACEHOLDER POLICY (Todd, 9/28): create all eight now; the seven without a real
 * number get `0000000` so the office can see at a glance which are incomplete, and
 * those seven stay field_publish_publicly = FALSE. A published placeholder would
 * render zeros as an apparent licence number on the one page a property manager
 * might copy into a bid packet. The rule for the office: a record publishes when
 * its number stops being zeros.
 *
 * NO numbers, dates, carriers or limits appear in the copy — the view renders those
 * from the fields, so nothing is ever maintained twice.
 *
 * Idempotent, matched on (type code + scope + teammate). Never overwrites a
 * credential number that is no longer the placeholder, and never flips
 * field_publish_publicly back to FALSE once the office has set it TRUE.
 *
 *   drush php:script web/scripts/seed_credential_records.php
 *   BOS_CRED_APPLY=1 drush php:script web/scripts/seed_credential_records.php
 */

$apply = getenv('BOS_CRED_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$PLACEHOLDER = '0000000';

$terms = [];
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'credential_types']) as $t) {
  $terms[(string) $t->get('field_credential_code')->value] = $t;
}

$D = [];

$D['CDA_COMM_APP'] = ['order' => 10, 'scope' => 'company', 'publish' => FALSE, 'desc' => <<<'HTML'
<p>In Colorado it is against the law to apply pesticides for hire without this licence. That includes weed control on a lawn, and it includes the neighbour's kid with a backpack sprayer and a business card.</p>

<p>The licence matters because of what happens when an application goes wrong. The wrong product on a lawn kills the lawn. The right product applied in the wrong conditions drifts, and drift does not stop at a property line — it lands in a vegetable garden, on a neighbour's ornamentals, or in an irrigation ditch that feeds someone's hay.</p>

<p>A licensed applicator carries records, training and accountability for that. An unlicensed one carries nothing, and when the damage shows up three weeks later there is no one to call.</p>
HTML];

$D['CDA_QS'] = ['order' => 20, 'scope' => 'teammate', 'uid' => 1443, 'publish' => FALSE, 'desc' => <<<'HTML'
<p>The business licence covers the company. The Qualified Supervisor is the person — tested, certified and named on the licence — who is responsible for what actually goes in the tank, at what rate, and in what conditions.</p>

<p>Those are two different things, and the difference is worth knowing. A company can be operating without a Qualified Supervisor on staff. If a company sprays your lawn, ask who theirs is. It is a fair question and it takes five seconds to answer if the answer exists.</p>
HTML];

$D['ABPA_TESTER'] = ['order' => 30, 'scope' => 'teammate', 'uid' => 1, 'publish' => TRUE, 'desc' => <<<'HTML'
<p>Your irrigation system is connected to the same water you drink. A backflow prevention assembly is what keeps that from mattering — when pressure drops in the main, water can be pulled backward through the system, and what comes back is whatever was sitting in the irrigation lines.</p>

<p>Colorado requires every testable assembly to be tested once a year under Regulation 11, Section 11.39 of the Primary Drinking Water Regulations. The test must be performed by a certified technician, and the report has to reach your water provider directly from the tester rather than by way of the customer.</p>

<p>We are certified to perform that test and we file the report with your provider. <a href="/services/backflow-prevention">How backflow prevention works and what the state requires</a>.</p>
HTML];

$D['GL'] = ['order' => 40, 'scope' => 'company', 'publish' => FALSE, 'desc' => <<<'HTML'
<p>Covers damage to your property caused by our work. A trailer into a garage door, a buried utility line cut, a limb through a roof.</p>

<p>We will send a current certificate naming you or your association as certificate holder, on request, at no charge.</p>
HTML];

$D['WC'] = ['order' => 50, 'scope' => 'company', 'publish' => FALSE, 'desc' => <<<'HTML'
<p>Covers our employees if they are injured on your property, so that the claim does not land on your homeowner's policy.</p>

<p>This is the coverage most often missing from a low bid, and the one where its absence costs a homeowner the most.</p>
HTML];

$D['AUTO'] = ['order' => 60, 'scope' => 'company', 'publish' => FALSE, 'desc' => <<<'HTML'
<p>Covers our trucks and trailers on the road and on your property. With roughly twenty-one vehicles moving between jobs across two counties every working day, it is not optional coverage and it is worth asking any contractor whether they carry it.</p>
HTML];

$D['USDOT'] = ['order' => 70, 'scope' => 'company', 'publish' => FALSE, 'desc' => <<<'HTML'
<p>Commercial vehicles above the federal weight threshold require USDOT registration, and registration means the fleet is subject to inspection, maintenance and driver requirements rather than running on the honour system.</p>

<p>For a homeowner this is mostly invisible. For a property manager awarding a multi-property contract, it is the difference between a vendor whose fleet is regulated and one whose is not.</p>
HTML];

$D['BORGERT'] = ['order' => 80, 'scope' => 'company', 'publish' => FALSE, 'desc' => <<<'HTML'
<p>A manufacturer certification for paver and retaining wall installation. Borgert certifies installers on their product — base preparation, bedding, compaction, edge restraint and the details that decide whether a patio is still flat in ten years.</p>

<p>Hardscape is the part of a landscape most often failed by what is underneath it rather than by the product on top. Freeze and thaw here is real, and a patio set on an inadequate base moves in its first winter.</p>
HTML];

printf("MODE: %s\n\n", $apply ? 'APPLY' : 'DRY-RUN (set BOS_CRED_APPLY=1 to write)');

$storage = $etm->getStorage('credential');
$created = 0;
$updated = 0;
foreach ($D as $code => $c) {
  $term = $terms[$code] ?? NULL;
  if (!$term) {
    printf("  ERROR no credential type for code %s — skipped\n", $code);
    continue;
  }

  /* Look up by TYPE + SCOPE, deliberately NOT by teammate: the seeder owns the
     copy, the OFFICE owns the assignment. Matching on the teammate too meant that
     reassigning a credential (the Qualified Supervisor moving from one person to
     another) made a re-run miss the record and create a DUPLICATE. On update the
     teammate is left exactly as it is; it is only set on CREATE. */
  $props = ['field_credential_type' => $term->id(), 'field_scope' => $c['scope']];
  $existing = $storage->loadByProperties($props);
  $record = NULL;
  if (count($existing) === 1) {
    $record = reset($existing);
  }
  elseif (count($existing) > 1) {
    // More than one holder of this type (e.g. a second certified tester). Match
    // the spec's holder if present; never guess.
    foreach ($existing as $candidate) {
      if ((int) ($candidate->get('field_teammate')->target_id ?? 0) === (int) ($c['uid'] ?? 0)) {
        $record = $candidate;
        break;
      }
    }
    if (!$record) {
      printf("  SKIP   %-14s %d records of this type exist and none matches the spec holder — resolve by hand\n", $code, count($existing));
      continue;
    }
  }

  $verb = $record ? 'update' : 'create';
  $number = $record ? (string) ($record->get('field_credential_number')->value ?? '') : '';

  printf("  %-6s %-14s order=%-3d scope=%-8s ", $verb, $code, $c['order'], $c['scope']);

  if ($apply) {
    if (!$record) {
      $values = ['type' => 'credential'] + $props + ['field_status' => 'active'];
      if ($c['scope'] === 'teammate') {
        // Only ever set on create — see the lookup note above.
        $values['field_teammate'] = $c['uid'];
      }
      $record = $storage->create($values);
    }
    // Never clobber a real number that has replaced the placeholder.
    if ($number === '' || $number === $PLACEHOLDER) {
      $record->set('field_credential_number', $code === 'ABPA_TESTER' && $number !== '' ? $number : ($code === 'ABPA_TESTER' ? '06-2512234' : $PLACEHOLDER));
    }
    $record->set('field_public_description', ['value' => trim($c['desc']), 'format' => 'full_html']);
    $record->set('field_list_order', $c['order']);
    // Publish flag: set TRUE where the spec says so; never flip an office TRUE back.
    $already = (bool) ($record->get('field_publish_publicly')->value ?? FALSE);
    $record->set('field_publish_publicly', $c['publish'] || $already);
    $record->save();
    printf("-> id %-3d number=%-12s publish=%s\n", $record->id(),
      $record->get('field_credential_number')->value, ($c['publish'] || $already) ? 'TRUE' : 'false');
    $record ? ($existing ? $updated++ : $created++) : NULL;
  }
  else {
    printf("number would be %-12s publish=%s\n",
      $number !== '' && $number !== $PLACEHOLDER ? $number . ' (kept)' : ($code === 'ABPA_TESTER' ? '06-2512234' : $PLACEHOLDER),
      $c['publish'] ? 'TRUE' : 'false');
    $existing ? $updated++ : $created++;
  }
}

printf("\n%d created, %d updated.%s\n", $created, $updated, $apply ? '' : ' (dry-run)');
print "Office rule: a record publishes when its number stops being zeros — flip field_publish_publicly then.\n";
