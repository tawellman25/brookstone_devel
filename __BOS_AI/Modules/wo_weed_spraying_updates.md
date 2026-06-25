# BOS Module — wo_weed_spraying (March 2026 Updates)

## Create-trap fix + abandoned-WO housekeeping (June 2026, branch `feature/spray-route-guard`)

> Status: built + verified locally, commit `7c8c2334`, **not yet deployed**.

### The bug — techs "routed back to the same WO"

Three paths decided whether to reuse vs create a weed_spraying WO, and **all three**
treated *any* open (non-done) WO for the property+year as a blocker and redirected to it:

1. `WeedSprayWorkOrderController::startWorkflow()` — the spray-route create link
   `/work-order/{property_id}/weed-spray/create`.
2. `wo_weed_spraying_form_work_order_weed_spraying_form_alter()` — crew add-form.
3. `wo_weed_spraying_entity_presave()` — the duplicate guard.

Design intent: one spray = one WO, **many WOs per property per year**. But because a
*stale* (created, never worked) or *resurrected* (completed, then reopened by a stray
clock-in — see the clock-in resurrection fix, 2026-06-19) open WO still counts as
"open," it trapped the property: every "create" bounced back to the dead WO. Example:
WO #49698 (19988 Iris Rd) was completed 05-12, resurrected to In Progress by a 14:37
clock-in, and then caught every new-spray attempt for that property.

### The fix — one helper, "genuinely active" only

All three paths now call `_wo_weed_spraying_find_active_open_wo(int $property_id, bool $heal)`,
which only treats a **genuinely-active** open WO as a blocker. WOs are classified by
`_wo_weed_spraying_classify_open_wo()`:

- **`active`** — recent, has work, or already invoiced → leave alone (and it's the one
  the create flow legitimately redirects to, for dedup).
- **`stale_empty`** — current-year, open, **>45 days** old, **zero** `wo_time_clock`,
  **zero** `wo_chemicals_used`, **not invoiced**, **never reached Complete** →
  auto-**Canceled** (`_wo_weed_spraying_cancel_stale()` via a `wo_status_updates` audit
  record; the number is kept as a canceled record). Done inline by the create flow
  (`$heal = TRUE`) and by the daily `hook_cron` sweep.
- **`resurrected`** — ever reached Complete (1097) then reopened → **flagged for office
  review, never auto-modified.** Auto-restoring it to Complete would re-fire the
  completion write-back and could overwrite the property's *current* last-applied date
  with this (possibly older) WO's date → spray-history corruption. The create flow simply
  stops routing techs to it (non-active), so a fresh WO is made; the office finalizes the
  flagged one by hand (Complete/Invoice, or Cancel).

The presave guard now calls the same helper with `$heal = FALSE` (read-only inside
presave — it must not modify other entities) and only throws on a genuinely-active
duplicate. Already-invoiced WOs are never touched. Everything is scoped to the current
year (matching the create flow; old WOs in legacy statuses are out of scope).

### Daily sweep + one-time cleanup

- `wo_weed_spraying_cron()` runs `_wo_weed_spraying_sweep_abandoned()` once per ~23h:
  cancels stale-empty, flags resurrected (logged for office review), counts active.
- `web/scripts/cleanup_stale_spray_wos.php` — the same sweep on demand. Dry-run by
  default; `SPRAY_CLEANUP_APPLY=1` to apply (`drush scr`). Idempotent.

### Status TIDs / done-set note

The "done" set used throughout is `[1097 Complete, 1098 Canceled, 1281 Invoiced,
1283 Warrantied]` (`_wo_weed_spraying_done_statuses()`). **Paid (1504)** is not in it
(there are currently 0 Paid weed_spraying WOs). Separately, some legacy 2024 WOs sit in
status **1301 "Active"** (invoiced) — out of scope here (year-scoped + invoiced-guarded);
flagged for separate review in `deferred_work.md`.

---

## 0-Gallon Inspection Visit Guard (Added March 2026)

### Background

Crew members sometimes drive to a property, inspect it, and determine
no spraying is needed. Previously there was no way to log this visit
without creating a billing record. Requiring 0-gallon WOs gets crews
in the habit of logging every stop.

### Guard in hook_entity_presave

Location: after $gallonsUsed is fetched, before any fee calculations.

```php
if ($gallonsUsed == 0) {
  $entity->set('field_wo_total', 0);
  return;
}
```

Behavior:
- 0 gallons → $0 billed, presave returns immediately
- All fee calculations (minimum spray fee $85, trip fee $35,
  ATV charge, labor) are skipped entirely
- Any gallons including 0.25 → normal billing applies (minimum fee fires)

### Write-back Still Fires

The 0-gallon guard returns early from presave, but write-back to
property_spraying_info:weed_spraying still fires via the queue:
- hook_entity_insert/update → queue → _wo_weed_spraying_handle_work_order()
- Sets field_last_applied_date, field_last_applied_by,
  field_last_amount_applied = 0
- Status indicator on weed spray route view resets to OK/green

### Field Supports Decimal Entry

field_total_gallons_applied is decimal(10,2).
Crews can enter 0.25 gallons — this triggers the minimum spray fee.
0 = inspection only, 0.25+ = service performed.

### Multi-Chemical Entry (Added March 2026)

field_chemical on wo_chemicals_used:weed_spraying set to unlimited
cardinality. Crew can select multiple chemicals in one form submission.

Custom submit handler (_wo_weed_spraying_chemicals_multi_submit):
- Intercepts wo_chemicals_used_weed_spraying_form on new entities
- Creates one wo_chemicals_used:weed_spraying entity per selected chemical
- All created entities get the same field values (gallons, work order, etc.)
- Each entity has exactly one field_chemical reference
- Redirect to parent work order after save
- Success message: "X chemical records created"

State reporting requirement: each chemical must be a separate record.
