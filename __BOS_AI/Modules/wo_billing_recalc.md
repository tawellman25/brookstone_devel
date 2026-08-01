# BOS Module — wo_billing_recalc

**Package:** Work Orders · **Type:** cross-cutting · **Enabled:** live (2026-08-01, commit `ed622023`)

## Purpose

Auto-recomputes a Work Order's billing totals when one of its **billing child
records** changes, so the office no longer has to re-open and re-save the
sign-off form just to refresh a total after fixing a material or adding an
equipment rental.

## The problem it removes

Each per-bundle `wo_*` module recomputes the WO's totals
(`field_material_chemical_total`, `field_rental_total`, `field_dump_fee_total`,
`field_wo_total`, …) in its `hook_entity_presave` — but **only when the
work_order itself is saved while status = Complete (1097)**. Editing a child
record (a material line item, a rental, a chemical, a dump load) does not save
the WO, so the totals go stale until someone manually re-saves the sign-off
form. This module supplies the missing trigger.

## Mechanism

`hook_entity_insert` / `hook_entity_update` / `hook_entity_delete` watch these
child entity types:

- `wo_material_list`
- `wo_material_list_item`
- `wo_rental_equipment`
- `wo_chemicals_used`
- `wo_material_dumping`

On a change it resolves the parent WO and **re-saves the work_order**, which
cascades through the existing `wo_*` presave recalc. It writes nothing itself —
the bundle modules remain the single source of the billing math.

`wo_time_clock` is intentionally **not** handled here — labor already
auto-recalcs via `wo_total_time`'s `_wo_total_time_trigger_wo_recalc()`. This
module is the materials/rentals/chemicals/dump counterpart.

### Child → WO resolution

| Child entity | Link to WO |
|---|---|
| `wo_chemicals_used`, `wo_material_dumping`, `wo_material_list` | `field_work_order` (direct) |
| `wo_rental_equipment` | `field_rented_for` |
| `wo_material_list_item` | `field_list_id` → `wo_material_list` → `field_work_order` |

## Status gate — Complete (1097) only

The re-save fires **only when the WO is Complete (1097)**. Once a WO is
**Invoiced (1281) / Paid (1504) / Warrantied (1283) / Canceled (1098)** its
totals are **frozen** — a child edit must not silently change a number that may
already be in QuickBooks (owner decision, 2026-08-01).

To revise a historical (billed) WO, the office **deliberately moves it back to
Complete** (a tracked `wo_status_updates` action), makes the edits — which now
auto-recalc while it's Complete — and re-invoices. Several purposeful, logged
actions, by design. In Progress (1092) needs no recompute (totals aren't final
until sign-off), so it is not triggered either.

## Safety

- **Loop guard:** a static per-WO-id flag prevents re-entry. (The WO re-save
  only *reads* the child entities via the `wo_*` queries; it does not save any
  watched child type, so re-entry can't actually occur — the guard is
  defensive.)
- **No config / no DB schema.** Pure code; enable with `drush en
  wo_billing_recalc`.
- Failures are caught and logged to the `wo_billing_recalc` channel; a recalc
  error never blocks the child edit itself.

## Related

- `wo_total_time` — the labor/time-clock equivalent trigger (the precedent).
- `wo_bundle_modules.md` — the per-bundle `wo_*` presave recalc this cascades
  into.
- `work_order_status.md` — status TIDs (Complete 1097, Invoiced 1281, etc.).
