# BOS Contract Sections — Field Label Overrides

This file defines **canonical UI labels** for shared fields used across
`contract_sections` bundles.

## Purpose

* Normalize client-facing language
* Keep bundle-specific schemas consistent
* Avoid per-bundle label drift

These labels represent **intent-level wording**, not execution terminology.

---

## Canonical Field Labels

```php
return [
  'field_do_you_want' => 'Included / Not Included',
  'field_client_notes' => 'Client Notes',
  'field_check_up_frequency' => 'Check-Up Frequency',
  'field_pre_emergent_areas' => 'Areas',
  'field_other_areas' => 'Other Areas',
  'field_service_application_notes' => 'Tech Notes',
  'field_set_your_budget' => 'Budget',
  'field_specific_plants' => 'Specific Plants',
  'field_mowing_frequency' => 'How Often',
  'field_spraying_frequency' => 'How Often',
  'field_aerating_season' => 'Season',
  'field_pre_emergent_season' => 'Season',
  'field_fertilizer_app_season' => 'Season',
  'field_deer_protection_actions' => 'Put Up / Take Down',
];
```

---

## Usage Rules

* These labels apply wherever the field appears on a Contract Section.
* Bundle-specific labels must not override these values.
* Execution-facing terminology must **not** be introduced here.
* If a new shared field is added to multiple Contract Section bundles,
  it must be added to this file.

---

## Invariants

* Field machine names remain unchanged.
* One field maps to exactly one canonical label.
* Labels describe **client intent**, not work performed.
