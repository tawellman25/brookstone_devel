# Foreman crew clock — specification

**Status:** BUILT and deployed 2026-11-03 — 21/21 acceptance tests pass on dev and live
**Written:** 2026-11-03
**For:** Todd, and whoever builds it
**Module:** extends `wo_clock` (see [`wo_clock.md`](wo_clock.md)); interacts with `wo_sign_off`, `wo_total_time`

---

## Why

Billing on most work order bundles is the **sum of individual clocked hours**
(`get_hours_for_*` adds up `field_total_time` across every `wo_time_clock`
entry). `wo_lawn_mowing` used to multiply headcount × minutes and was
deliberately moved onto the same sum — its own comment records why:

> *"Sum already represents total man-hours: Phase 2c reconciliation creates one
> wo_time_clock entry per crew member, so each crew member's hours are counted
> exactly once."*

So the money depends on **every crew member having an entry**. Today, when only
one person clocks in, that is patched afterwards by sign-off reconciliation
**inventing** a window for everybody else — earliest clock-in on the WO to
latest clock-out.

That works on a job visited once. It fails on a job visited twice:

| WO#54221 — Fall Cleanup, created 10/07, completed 11/03 | |
|---|---|
| Herbert Munoz, visit 1 | 10/23 14:15 → 14:39 (24 min) |
| Herbert Munoz, visit 2 | 11/03 17:38 → 21:22 (3h 44m) |
| Window reconciliation derived for the other 7 crew | **10/23 14:15 → 11/03 21:22 = 271.11 h each** |

All seven were rejected by the entry-hours cap, and the work order **completed
with one teammate's hours out of eight**. Billing read ~4 hours for a crew day.
**1,195 work orders have clock entries spanning more than one day; 806 span more
than three.** Every one is a candidate.

The fix is not better guessing. It is to **observe the window instead of
inferring it** — let the foreman clock the crew in and out directly, so
reconciliation goes back to being a rare correction rather than load-bearing
infrastructure.

---

## What already exists (verified 2026-11-03, not assumed)

This is why the build is small — most of the model is already right.

- **One entry per person.** `wo_time_clock` rows carry `field_teammate` with
  their own `field_start_time` / `field_end_time`. There is no shared crew
  record, so independent in/out times need nothing built.
- **Self-service already works.** A crew-only account can create, view and edit
  **their own** entries. 254 entries are tagged `source=manual`.
- **Origins are already tagged** via `field_source`:

  | value | count |
  |---|---|
  | `signoff_reconciliation` | 2,902 |
  | `wo_clock_button` | 2,313 |
  | `manual` | 254 |
  | `wo_clock_intervention` | 24 |
  | `flag` | 9 |

- **Routes exist:** `POST /clock/in/{wo_id}` and `POST /clock/out/{entry_id}`,
  both `_csrf_request_header_token`, with silent GPS capture
  (`field_clock_out_location`, `field_clock_out_distance_ft`).
- **Audit links exist:** `field_created_signoff_*` / `field_closed_signoff_*`
  already record which sign-off created or closed an entry.
- **Permissions (after 2026-11-03):** teammates hold `edit own` / `delete own`;
  `edit any` is now supervisor, office and site_admin only.

**Already satisfied by the above, with no work:**

| Requirement | Why it already holds |
|---|---|
| A member clocks out early without affecting the rest | their row is closed; nobody else's row is touched |
| A member arrives late and clocks in themselves | normal green button, own row, own start |
| A member edits their own time | `edit own`, long-standing |

**The only missing piece is the foreman convenience.**

---

## Scope

### In

1. **Clock in crew** on a work order: one action opens an entry for several
   people at once.
2. **Clock out crew**: closes the entries that are still open.
3. Both leave every individual entry independently editable and closable.

### Out

- Changing how billing sums hours. It already sums per-person entries.
- Changing the entry-hours cap. **Todd, 2026-11-03: "Leave the cap."**
- Retiring sign-off reconciliation. It stays as the correction path; this
  reduces how often it is needed.
- Backfilling the 1,195 historical multi-day work orders.

---

## Design

### Clock in crew

On the work order, beside the existing clock-in button, for a user permitted to
operate it:

1. Show the crew. Default the list from the work order's `scheduling`
   records (`field_assigned_to`), all pre-ticked, each row removable, plus a
   way to add somebody not on the schedule — crews change on the morning.
2. On submit, create **one `wo_time_clock` entry per ticked person**, all with
   the same `field_start_time` (now), `field_source = wo_clock_crew`.
3. **Skip anyone who already has an open entry on that work order** rather than
   creating a second one. Someone who clocked themselves in at 7:00 keeps their
   7:00, and the foreman's 7:15 crew action does not overwrite or duplicate it.

### Clock out crew

1. Close every **open** entry on the work order, setting the same
   `field_end_time` (now).
2. Leave already-closed entries alone — that is the early finisher, and their
   earlier time must survive.
3. Report what was closed, by name.

### Late arrival, early finish, corrections

No new mechanism. A late arrival uses the existing button; an early finisher
uses the existing clock-out; anyone fixes their own entry afterwards. These work
today because entries are per-person.

---

## ⚠ The cap problem this design inherits

The entry-hours cap (4 h default, 14 h for `WO_TOTAL_TIME_LONG_JOB_BUNDLES`)
is enforced on **save**, and the only exemptions are the
`field_time_limit_override` checkbox or `administer eck entities`.

A **crew clock-out is programmatic**, like reconciliation was — so a crew
finishing a 6-hour day on a 4-hour-cap bundle would have **every entry
rejected at once**, which is precisely the failure this feature exists to
prevent, reproduced at crew scale.

**Required:** the crew clock-out must surface the cap the way the sign-off form
now does — name the limit, name the people, and offer the same "this long entry
is correct" confirmation — rather than failing silently or writing nothing. A
partial success (three closed, four rejected) is the worst outcome and must not
be possible: close all or none, and say which.

**Resolved 2026-11-03 — the default cap is now 8 h** (`field_max_entry_hours`,
was 4). Todd: *"Can we move that cap to 8 hours?"* — and, on being shown it:
*"I actually don't remember setting it at 4 hours."* He did not: commit
`39bd75f3` (2026-05-16) created the field **already defaulted to 4**. That
commit's intent was narrow — catching forgotten clock-outs, citing 17–18 hour
runaways — so **4 was never aimed at a six-hour Christmas-decoration day; it
caught it as collateral**, and nobody noticed for six months because the
override checkbox quietly absorbed it on the ordinary form. It only became
visible when the sign-off path, which has no checkbox, hit it and failed.

Measured before changing: of **27,218** entries on default-cap bundles, **159**
sit between 4 and 8 h (Christmas decorations 59, sprinkler start-up 23, weed
spraying 17 — real full days), **24** between 8 and 14 h, and **56** over 14 h.
At 8 h the original purpose is intact — the 80 genuinely suspect entries are
still caught — and the cap stops arguing with real work. Long-job bundles
remain at 14 h.

---

## Open decisions

**Resolved 2026-11-03 — any teammate may operate it.** Todd: *"it looks like we
better let any member use it. The crew leader may change on different WOs."* The
routes therefore require only `access content`, exactly like the individual
clock. Gating on a `supervisor` role would block whoever is actually holding the
phone. Accountability lives in the data instead: every crew entry records who
pressed the button, in `field_notes` and via `field_source = wo_clock_crew`.
2. ~~**GPS on crew entries.**~~ **Resolved 2026-11-03.** Todd: *"if the foreman
   is including them they better also be their"*. Correct, and it settles the
   design: a foreman adding someone to a crew clock-in is **attesting they are
   on site**, and that is a supervisor making a statement he is accountable
   for. So **stamp the captured location on every crew entry**, and rely on
   `field_source = wo_clock_crew` to record that it came from one foreman
   action rather than five independent captures. That keeps the record honest
   — anyone reading it later can see exactly what it is — without discarding
   location data that is genuinely useful. Do **not** silently copy it with no
   marker, which would misrepresent one reading as five.

**Resolved 2026-11-03 — who may edit someone else's entry.** `edit any` was
removed from **`teammates` only**. Supervisors, office staff (`administration`)
and `site_admin` keep it: *"office staff and admin should be able to edit anyone
else's time entries"*. Verified against the access handler on dev and live — a
crew-only account can still edit its own entry and is denied on another's.

⚠ An intermediate reading stripped office and admin too, and was caught before
being applied. Do not reinstate it: with those two excluded, nobody but
supervisors and the superuser could correct a crew member's time, which breaks
month-end. The script carries the same warning.


---

## Acceptance

- A foreman clocks in 5 crew; 5 entries exist, same start, `source=wo_clock_crew`.
- One of them clocks out 2 hours early; their entry closes, the other 4 stay open.
- A 6th arrives late and clocks in; their entry starts late, nobody else changes.
- The foreman clocks out the crew; the 4 open entries close, the early finisher's
  earlier end time is **unchanged**.
- `field_total_time` on the work order equals the sum of all six real entries.
- A crew member can still edit their own entry and **cannot** edit another's.
- On a capped bundle, an over-cap crew clock-out names the limit and the people
  and writes **nothing** until confirmed — never a partial close.
- Sign-off on that work order finds no missing crew, so reconciliation does not run.

---

## Related

- [`wo_clock.md`](wo_clock.md) — the existing per-person clock, GPS capture, `field_source`.
- [`wo_sign_off.md`](wo_sign_off.md) — reconciliation, and the 2026-11-02 change
  that makes it **ask** for times rather than invent them when there is nothing
  to anchor to. The multi-day case described above is still open.
- `Governance/change_log.md`, 2026-11-02 and 2026-11-03 — WO 54225, WO 54221,
  the CSRF clock-in failure, and the permission narrowing.
