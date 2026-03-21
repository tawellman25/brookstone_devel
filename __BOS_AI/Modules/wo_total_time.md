# BOS Module — wo_total_time

Module: wo_total_time
Package: Work Orders

Purpose:
- Custom field type: `wo_total_time` — stores computed decimal hours
- Computes `field_total_time` from start/end datetime fields on `wo_time_clock` entities
- Triggers WO billing recalculation when time clock entries are saved/updated

---

## hook_entity_presave

Fires on `wo_time_clock` entities.

Behavior:
- Reads field_start_time and field_end_time
- Computes difference in hours (rounded to 2 decimal places)
- Sets field_total_time on the time clock entry
- If either time is missing: sets field_total_time = NULL

Also handles manual entry ownership:
- On POST requests where field_teammate differs from entity owner
- Updates entity owner to match field_teammate
- Logs the UID change

---

## hook_entity_insert / hook_entity_update (Added March 2026)

Both hooks call `_wo_total_time_trigger_wo_recalc()`.

### _wo_total_time_trigger_wo_recalc()

Purpose:
When a wo_time_clock entry is saved, trigger the parent WO to
recalculate its billing totals.

Guards:
- Entity type must be wo_time_clock
- field_work_order must be set
- Static processing guard prevents infinite loops (keyed by WO ID)
- WO status must NOT be in protected statuses

Protected statuses (no recalc):
- Invoiced: TID 1281
- Paid: TID 1504
- Canceled: TID 1098

Behavior:
- Loads parent WO via field_work_order
- Checks WO status against protected list
- If not protected: calls $wo->save() which triggers presave billing hooks
- Unsets static guard on completion

Use case:
Office staff correcting a clock-in/out time (e.g., 3:00 AM entered
instead of 3:00 PM) — saving the correction now automatically
recalculates the WO's labor total and grand total.

---

## Status

Updated: March 2026
Added: WO billing recalc trigger on time clock save
