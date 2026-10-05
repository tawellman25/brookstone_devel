<?php

declare(strict_types=1);

/**
 * Add the "lights you already own" section to the Holiday Decorations service
 * body, so it renders both on /services/christmas-decorations and as a
 * "How we do it" accordion on /holiday-lights.
 *
 * It lives in the TERM BODY rather than in the landing-page template because
 * the office owns this copy and can edit it with no deploy — the same reason
 * every other accordion on that page comes from here.
 *
 * The rate and minimum are TOKENS, never typed figures: [holiday-hang-rate] and
 * [holiday-hang-min] resolve from Business Settings at render, so changing the
 * price in one place changes it on both surfaces. Typing $3.00 into copy is
 * exactly the problem recorded on 2026-10-03, where a published per-foot figure
 * lived only in page copy.
 *
 * Idempotent: keyed on the heading. Backs the previous body up first.
 *
 *   drush php:script web/scripts/add_holiday_hang_section.php
 *   BOS_HANG_APPLY=1 drush php:script web/scripts/add_holiday_hang_section.php
 */

$apply = getenv('BOS_HANG_APPLY') === '1';
print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_HANG_APPLY=1 to write)\n\n";

$HEADING = 'Can we hang lights you already own?';

// Resolved by ALIAS, not by name — the office owns term names and this one has
// been both "Christmas Decorations" and "Holiday Decorations".
$path = \Drupal::service('path_alias.manager')->getPathByAlias('/services/christmas-decorations');
if (!preg_match('#^/taxonomy/term/(\d+)$#', $path, $m)) {
  print "ERROR: /services/christmas-decorations does not resolve to a term (got $path). Aborting.\n";
  return;
}
$term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load((int) $m[1]);
if (!$term || !$term->hasField('field_service_public_desc')) {
  print "ERROR: term or field missing. Aborting.\n";
  return;
}

$body = (string) $term->get('field_service_public_desc')->value;
$format = $term->get('field_service_public_desc')->format ?: 'full_html';
printf("  term %d \"%s\", body %d chars, format %s\n", $term->id(), $term->label(), strlen($body), $format);

if (str_contains($body, $HEADING)) {
  print "  section already present — nothing to do.\n";
  return;
}

$section = <<<HTML
<h2>$HEADING</h2>

<p>Yes. We will install them at the start of the season and take them down in January for <strong>[holiday-hang-rate] per foot</strong>, with a [holiday-hang-min] minimum. That is labour only — there is no service guarantee during the season and no off-season storage, because they are not our lights.</p>

<p><strong>We need to see them first.</strong> What we charge depends on the condition they are in, and an additional fee may be charged for clips and for detangling the strands. Call the office and we will come and look before we quote it.</p>
HTML;

// Append at the end: it is the alternative offer, so it reads last rather than
// interrupting the description of the main programme.
$new = rtrim($body) . "\n\n" . $section . "\n";

printf("  + appending the section (%d chars)\n", strlen($section));
printf("    tokens used: %s\n", implode(', ', array_values(array_filter(
    ['[holiday-hang-rate]', '[holiday-hang-min]'],
    fn($t) => str_contains($section, $t)))));

if (!$apply) {
  print "\n(dry-run — nothing written)\n";
  return;
}

$backup = sprintf('%s/docs/backups/holiday-term-body-%s.json', DRUPAL_ROOT . '/..', date('Ymd-His'));
@mkdir(dirname($backup), 0775, TRUE);
file_put_contents($backup, json_encode([
  'term' => $term->id(),
  'field_service_public_desc' => ['value' => $body, 'format' => $format],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
print "  backup: $backup\n";

$term->set('field_service_public_desc', ['value' => $new, 'format' => $format]);
$term->save();
\Drupal\Core\Cache\Cache::invalidateTags(['taxonomy_term:' . $term->id()]);
print "  saved.\n";
