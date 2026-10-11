<?php
/**
 * The HOA public showcase display must show the showcase, and nothing else.
 *
 * Found 2026-10-10 by the legacy-route sweep: all 14 HOA common-area pages were
 * serving, to anonymous visitors, the owner's name and PHONE NUMBER, nine
 * spray-application EVAs, fertilizing and check-up history, sprinkler and
 * backflow detail, lawn-maintenance and snow-removal records, property service
 * instructions, the staff author's name, and an "Add Another System" link.
 *
 * The view-mode routing was never the problem -- bos_hoa correctly gives an
 * anonymous visitor the `public` view mode. The problem is what that display
 * had on it: 27 components rather than the handful the showcase was specified
 * with on 2026-09-07 ("safe showcase fields ONLY ... every other field
 * removed"). The most likely way they arrived is 2026-09-22's
 * add_hoa_to_property_evas.php, which added the hoa bundle to every
 * property-detail EVA -- an EVA added to a bundle attaches to its displays, and
 * that entry's claim that "hoa.public showcase untouched" did not hold.
 *
 * Only properties.hoa.public is touched. properties.hoa.default -- the staff
 * page -- is left exactly as it is.
 *
 * Dry run by default; BOS_HOA_DISPLAY_APPLY=1 to write. The previous component
 * list is printed either way so it can be restored by hand.
 */
$apply = getenv('BOS_HOA_DISPLAY_APPLY') === '1';

// Everything the public showcase is for, and nothing that is about a customer.
// property_photos_public is the curated gallery, gated on field_public_ok.
$KEEP = [
  'title',
  'field_neighborhood',
  'field_hoa_public_desc',
  'field_service_start',
  'field_service_end',
  'property_photos_public_entity_view_1',
];

$display = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load('properties.hoa.public');
if (!$display) { print "properties.hoa.public does not exist\n"; return; }

$before = array_keys($display->getComponents());
sort($before);
printf("%s\n\n", $apply ? '*** APPLYING ***' : 'DRY RUN (set BOS_HOA_DISPLAY_APPLY=1 to apply)');
printf("before: %d components\n", count($before));
foreach ($before as $c) {
  printf("   %-4s %s\n", in_array($c, $KEEP, TRUE) ? 'keep' : 'DROP', $c);
}

$removed = [];
foreach ($before as $c) {
  if (!in_array($c, $KEEP, TRUE)) { $removed[] = $c; }
}
printf("\nwould remove %d, keep %d\n", count($removed), count($before) - count($removed));

if (!$apply) { print "\nnothing written (dry run)\n"; return; }

foreach ($removed as $c) { $display->removeComponent($c); }
$display->save();

$after = array_keys($display->getComponents());
sort($after);
printf("\nafter: %d components\n", count($after));
foreach ($after as $c) { print "   $c\n"; }
print "\nproperties.hoa.default (the staff page) was not touched: "
  . count(\Drupal::entityTypeManager()->getStorage('entity_view_display')->load('properties.hoa.default')?->getComponents() ?? []) . " components\n";
