# BOS Module — wo_shared

Module: wo_shared
Package: BOS Custom

Purpose:
- Auto-create property_spraying_info records when spray-related Work Orders are inserted
- Prevent missing property_spraying_info records from breaking spray route views
- Provide bulk backfill drush command for existing WOs

---

## Files

```
wo_shared/
  wo_shared.info.yml
  wo_shared.module
  wo_shared.services.yml
  src/
    Commands/
      WoSharedCommands.php
```

---

## hook_entity_insert (wo_shared.module)

Fires when any work_order entity is inserted.

Guards:
- Entity type must be work_order
- Bundle must be in the spray bundle map
- Mapped spraying_info bundle must not be NULL

Behavior:
- Looks up bundle → property_spraying_info type mapping
- Loads field_property target_id from the WO
- Checks if property_spraying_info record already exists for that property + bundle
- If not found: creates a bare record (type + field_property only)
- Logs notice on creation

Never overwrites existing records.

---

## Bundle → property_spraying_info Mapping

```php
function _wo_shared_get_spraying_info_bundle_map(): array {
  return [
    'pre_emergent'           => 'pre_emergent',
    'weed_spraying'          => 'weed_spraying',
    'aspen_twig_gall'        => 'aspen_twig_gall',
    'cooley_spruce_gall'     => 'cooley_spruce',
    'deciduous_bore'         => 'deciduous_bore',
    'dormant_oil'            => 'dormant_oil',
    'grub_prevention'        => 'grub_prevention',
    'pinion_pine_ips_beetle' => 'ips_beetle',
    'trunk_bore'             => 'trunk_bore',
    'deer_prevention'        => NULL,  // No property_spraying_info bundle
  ];
}
```

---

## Drush Command — wo-shared:backfill-spraying-info

Alias: wo-bsi
Service: wo_shared.commands

Purpose:
Backfills missing property_spraying_info records for ALL existing spray WOs.
Run after deploy on live at start of each season.

Behavior:
- Loops through all WO bundles in the map (skips NULL)
- Queries ALL work_orders of that bundle (no status filter)
- For each WO, checks if property_spraying_info record exists
- Creates bare record if missing
- Deduplicates: skips if same property already processed in same bundle
- Reports created count per bundle and total

```bash
drush wo-shared:backfill-spraying-info
# or
drush wo-bsi
```

Expected counts (March 2026):
- Local: 45 records created across 9 bundles
- Live: 37 records created across 9 bundles

---

## Why This Module Exists

Views that display spray WOs alongside property_spraying_info data
use a relationship: `reverse__property_spraying_info__field_property`.

Even as a LEFT JOIN, adding a filter on the relationship's bundle type
effectively converts it to an INNER JOIN — excluding WOs where no
property_spraying_info record exists for that property.

Without wo_shared, new properties or properties added mid-season
would be excluded from spray route views even if they had valid WOs.

---

## work_order presave (added 2026-05)

`wo_shared_work_order_presave()` does two cross-cutting things on
every work_order save:

1. **Recalc `field_total_time` while Complete.** When
   `field_status == 1097` (Complete), set `field_total_time` to the
   sum of the WO's closed `wo_time_clock` entries. Skipped for any
   other status (pre-completion data is mid-flight; Invoiced/Paid/
   Canceled/Warrantied preserve the captured-at-billing value).
   Completes the previously-orphaned chain where
   `wo_total_time`'s `_wo_total_time_trigger_wo_recalc()` saved the
   parent WO on clock-entry change but nothing actually recalced for
   non-mowing bundles. For `lawn_mowing`, `wo_lawn_mowing` also
   recalcs — both produce the same value (idempotent).

2. **Invoiced-transition guard**
   (`_wo_shared_assert_invoiced_transition_valid`). Blocks any save
   that sets `field_status` to Invoiced (1281) unless the prior
   status was Complete (1097) or Invoiced (no-op resave). Throws
   `EntityStorageException` with an office-facing message. Catches
   the recurring "office invoices before crew signs off" mistake
   that left orphan clock entries + incomplete tasks lists blocking
   crews from creating new mowing WOs (WO 49892 et al., 2026-05-13).
   Bypass for scripts/migrations: `$wo->_skip_invoiced_guard = TRUE;`
   — no UI path sets it, so office staff cannot bypass via the form.

---

## Status

Updated: 2026-05-16 (work_order presave: total_time recalc + Invoiced guard)
Created: March 2026
Live backfill counts: 37 records (March 2026 season)
