# BOS — Irrigation Check Up Generator

## Why This Exists
Irrigation Check Ups are **contract-obligated, recurring services** that must occur
on a predictable cadence and geographic route, without relying on office staff
to remember to create or schedule Work Orders.

Manual creation does not scale, introduces missed visits, and obscures workload
visibility. This generator enforces BOS intent → execution rules while providing
reliable, forward-looking scheduling for operations and planning.

This is **automation infrastructure**, not a convenience feature.

---

## Purpose
Automatically generate and schedule contract-obligated **Irrigation Check Up**
Work Orders based on:

- Contract intent
- Service configuration
- Geographic routing (Zipcode)
- Scheduling constraints
- Seasonal rules

The generator removes human memory from the loop while preserving BOS
governance and auditability.

---

## Core BOS Principles Enforced
- Contract Sections represent **intent**, not execution
- Work Orders represent **execution**
- Services taxonomy is the **mapping authority** for Work Order bundles
- Contracts gate execution
- Scheduling is **planning**, not execution
- Completed Work Orders are immutable
- High-frequency services must not consume bounded pointer fields

---

## What This Generator Does
- Runs automatically via **cron** (daily)
- Can be triggered manually via Drush:
  - `bos:checkups:generate`
  - `bos:checkups:generate --force`
- Creates **scheduled** Work Orders for Irrigation Check Ups
- Plans work on a **rolling 21-day horizon**
- Schedules work to **zipcode-based route days**
- Is fully **idempotent** (no duplicates)

---

## Eligibility Requirements
**All conditions must be met.**

### Contract
- Bundle: `residential`
- `field_contract_year` = current calendar year
- Contract Status ∈:
  - Approved
  - Work Orders Created
  - Assigned

---

### Contract Section
- Has `field_check_up_frequency`
- Has `field_contract`
- Has `field_service`
- If present, `field_do_you_want` must be truthy

---

### Service Term
- `field_work_order_service = 1`
- `field_service_bundle` maps to a valid `work_order` bundle

---

### Property
- Has `field_zipcode_reference`

---

### Zipcode
- Has `field_check_up_route_day`
- Route day is stored as ISO weekday:
  - `1 = Monday … 7 = Sunday`

Missing zipcode route day **blocks generation** by design.

---

## Frequency Rules

| Frequency   | Behavior |
|------------|----------|
| Weekly     | Every 7 days on route day |
| Bi-Weekly  | Every 14 days on route day |
| Monthly    | Every 28 days on route day (operational month) |
| Mid-Season | One occurrence between late June and early July |

---

## Scheduling Rules
- Planning horizon: **21 days**
- Work Orders are created with:
  - Status: **Open**
  - Scheduled date via **Scheduling entity**
- Scheduling is visible in WO Schedule EVA
- Generator will not create duplicate Work Orders for the same date

---

## Season Rules
- Irrigation season: **March 15 – October 15**
- Work Orders are only scheduled **within the season window**
- Generator may enqueue without creating Work Orders when out of season
- This preserves execution truth while allowing automation readiness

---

## Automation & Safety Controls
- Cron dispatch is guarded to **once per day**
- Lock prevents concurrent or overlapping dispatch
- Drush supports `--force` override for manual control
- Queue processing is resumable and bounded
- Silent skips prevent log noise for expected conditions

---

## Operational Notes
- This generator **does not** use Contract Section Work Order pointer fields
- Check Ups are high-frequency and must not consume bounded slots
- All generated Work Orders:
  - Are linked to the **Contract**
  - Are **not** linked back to the Section via pointer fields
- Geographic routing is controlled entirely via Zipcodes

---

## Common Reasons No Work Orders Are Created
- Contract is not approved
- Contract year is not current
- No sections have `field_check_up_frequency`
- Zipcode route day is missing
- Outside irrigation season
- Service term is not WO-enabled

All skips are intentional and preserve BOS invariants.

---

## Related BOS Components
- Contracts (Residential)
- Contract Sections
- Services taxonomy
- Zipcodes (routing authority)
- Scheduling entity
- Work Orders

---

## Change Control
Any changes to:
- frequency logic
- season window
- routing rules
- horizon length
- contract gating

**must be reviewed as BOS automation changes** and documented here.

---

## Bug Fixes

### April 2026 — Null clone crash in mostRecentScheduledDate()
**Symptom:** `__clone method called on non-object` in `DateTimePlus->__clone()`.
**Root cause:** `field_date` is smartdate — `->value` returns a Unix timestamp (integer),
not a date string. Code was calling `new DrupalDateTime(substr(timestamp, 0, 10))` which
created a broken DateTime object that crashed on `clone`.
**Fix:** Use `DrupalDateTime::createFromTimestamp()` for numeric values. Added nullable
type hint on `nextWeekdayOnOrAfter()` and safety counter on while loop.

### April 2026 — field_date all_day flag
Scheduling entities created by the checkup generator now set `all_day: TRUE` on
`field_date` (smartdate) in addition to duration 1439. Also sets
`field_scheduled_date_and_time` alongside `field_date` for legacy field compat.
