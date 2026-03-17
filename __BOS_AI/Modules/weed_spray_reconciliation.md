# BOS — Weed Spray Contract Reconciliation

## Purpose

Identify which properties are on the weed spray route but have NOT returned
a signed contract for the current year. Enables bulk-removal of non-renewals.

---

## Key Field: field_active_contract_year

Entity: property_spraying_info:weed_spraying
Type: integer
Label: Active Contract Year

Stores the year (e.g. 2026) when property has a current signed weed spray contract.
NULL = no current contract on file.

Set by: _contract_residential_maybe_sync_weed_spray_route()
Cleared when: both beds and misc sections set to No on same contract.

---

## Automation Flow

1. Office staff enters 2026 residential contract
2. Weed spray section saved with field_do_you_want = Yes (1)
3. hook_entity_insert/update fires on contract_sections
4. Sync function runs:
   - Finds/creates property_spraying_info:weed_spraying
   - Sets field_active_contract_year = 2026
   - Sets field_spray_route = TRUE
   - Sets beds or misc contracted = TRUE
   - Copies spraying frequency

---

## Backfill Script (Run on Live After Each Deploy)

Update the year value each season before running.

```php
$count = 0;
$contracts = \Drupal::entityTypeManager()
  ->getStorage('contracts')
  ->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'residential')
  ->condition('field_contract_year', 2026)  // UPDATE YEAR EACH SEASON
  ->execute();

foreach ($contracts as $contract_id) {
  $contract = \Drupal::entityTypeManager()->getStorage('contracts')->load($contract_id);
  $property_id = $contract->get('field_property')->target_id;
  if (!$property_id) continue;
  $beds_section_id = $contract->get('field_weed_spraying_of_landscape')->target_id;
  $misc_section_id = $contract->get('field_weed_spraying_of_misc_area')->target_id;
  if (!$beds_section_id && !$misc_section_id) continue;
  $beds_yes = FALSE;
  $misc_yes = FALSE;
  if ($beds_section_id) {
    $section = \Drupal::entityTypeManager()->getStorage('contract_sections')->load($beds_section_id);
    if ($section && $section->get('field_do_you_want')->value === '1') $beds_yes = TRUE;
  }
  if ($misc_section_id) {
    $section = \Drupal::entityTypeManager()->getStorage('contract_sections')->load($misc_section_id);
    if ($section && $section->get('field_do_you_want')->value === '1') $misc_yes = TRUE;
  }
  if (!$beds_yes && !$misc_yes) continue;
  $spray_infos = \Drupal::entityTypeManager()
    ->getStorage('property_spraying_info')
    ->loadByProperties(['type' => 'weed_spraying', 'field_property' => $property_id]);
  if (empty($spray_infos)) continue;
  $spray_info = reset($spray_infos);
  if ($spray_info->get('field_active_contract_year')->value != 2026) {
    $spray_info->set('field_active_contract_year', 2026);
    $spray_info->save();
    $count++;
  }
}
echo 'Backfilled: ' . $count . ' properties' . PHP_EOL;
```

Expected counts:
- Local (March 2026): 93 properties
- Live (March 2026): 128 properties

---

## Reconciliation View

Path: /admin/weed-spray/reconciliation
Machine name: admin_weed_spray_reconciliation
Base entity: property_spraying_info:weed_spraying

Columns: VBO checkbox, Route Order, Property, Address,
         Beds, Misc, On Route, Last Applied, Contract Year

Exposed filters:
- On Route (True/False)
- Beds Contracted (True/False)
- Misc Contracted (True/False)
- Contract Year (numeric equals)
- No 2026 Contract (IS NULL / Has Contract Year dropdown)

VBO: spray_route_remove_action (Remove from Weed Spray Route)
Sets: spray_route=FALSE, beds=FALSE, misc=FALSE, active_contract_year=NULL

---

## Annual Process

Each year (e.g. 2026 → 2027):
1. Run backfill script with updated year after drush cim on live
2. Use "No Contract Year" / Contract Year filter to find non-renewals
3. No field changes needed — field_active_contract_year is year-agnostic

---

## Key Contract Fields

Section slots on residential contract:
- field_weed_spraying_of_landscape → contract_sections:weed_spraying_landscape_beds
- field_weed_spraying_of_misc_area → contract_sections:weed_spraying_of_misc_areas

Section fields:
- field_do_you_want: Yes=1, No=2, Request Quote=3
- field_spraying_frequency: entity_reference to frequency term
- field_contract: back-reference to parent contract

---

## Status

Created: March 2026
Live backfill count: 128 properties (2026 season)