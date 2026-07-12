# BOS Module — wo_clock

Module: `wo_clock`
Package: Work Orders
Status: Phase A (built 2026-07-03, local-validated; not yet deployed to live)

## Purpose

Clock-in/out UX for `wo_time_clock` entries, replacing the flag-based timer
(`wo_timer_flag_update`). Preserves the "one button on the WO page" semantic but
adds: state awareness (detects open entries on other WOs), self-service recovery
(alert region + modal intervention), and **silent GPS capture** at clock-in and
clock-out as dispute evidence. Ships **alongside** the flag path — the flag path
stays fully functional during the transition (see Coexistence).

## Fields added to `wo_time_clock:entry`

All four are optional and **hidden on the default form display, visible on the
default view display** (weight 30-33) — GPS/distance never surface on operational
dashboards, only on admin entity inspection.

| Field | Type | Label |
|---|---|---|
| `field_clock_in_location` | geofield | Clock-In Location |
| `field_clock_out_location` | geofield | Clock-Out Location |
| `field_clock_in_distance_ft` | decimal(10,2) | Clock-In Distance from Property (ft) |
| `field_clock_out_distance_ft` | decimal(10,2) | Clock-Out Distance from Property (ft) |

> **Deviation:** the spec named the distance fields `field_clock_in/out_distance_from_property`,
> which exceed Drupal's **32-char** machine-name limit. Shortened to `_distance_ft`
> (labels unchanged). Fields created via the entity-API workaround (cim silent-skip
> bug — see `drupal_bos_gotchas.md`); per-environment UUIDs patched into sync.
> GPS is stored as geofield WKT `POINT (lon lat)`.

## WoClockService (`wo_clock.clock_service`)

Transport-agnostic domain service. Datetime storage is UTC `Y-m-d\TH:i:s`.

| Method | Behavior |
|---|---|
| `getOpenEntriesForUser(int $uid, ?int $excludeWoId = null): array` | Open entries (NULL `field_end_time`) by `field_teammate` or owner uid; oldest-first; optional WO exclusion. |
| `getCurrentEntryOnWo(int $uid, int $woId): ?entity` | The user's open entry on a WO, or null. |
| `createOpenEntry(int $uid, int $woId, array $extra = []): entity` | Builds an **unsaved** open entry with **`field_end_time` guaranteed cleared**. The single place that owns the open-entry guarantee — any code creating an intended-open entry should use this (see the `field_end_time` default-of-"now" gotcha in `drupal_bos_gotchas.md`). |
| `clockIn(int $uid, int $woId, ?float $lat, ?float $lon, ?string $noteContext): entity` | **Refuses (throws) if the WO is billed — `woIsLocked()` true (Invoiced 1281 / Paid 1504)** — a billed WO is closed to time entry; corrections are an office action. Otherwise creates an **open** entry via `createOpenEntry()`, then stores GPS / prepends a note if given, and saves. |
| `woIsLocked(int $woId): bool` | TRUE when the WO is in a billed/locked status (**Invoiced 1281 / Paid 1504** — `LOCKED_STATUSES`) that is closed to any new clock-in. Added 2026-07-11 (`cc2ffb38`). |
| `clockOut(int $entryId, ?float $lat, ?float $lon): entity` | Sets end=now, stores clock-out GPS. Respects Phase 1 guards (throws propagate). |
| `closeEntry(int $entryId, ?int $endTimestamp, bool $auditNote): entity` | Retroactive close (now or a timestamp). Prepends `[Closed via intervention MM/DD/YYYY h:i AM/PM by {name}]` when `$auditNote`. Sets the `_signoff_reconciliation` bypass **only when the parent WO is Invoiced/Paid** (matches wo_total_time Phase 1 Guard 4). |
| `calculateDistanceFeet(...)` | Haversine, Earth radius 20,902,231 ft. Verified: 0.01° lat = 3648 ft; identical points = 0. |
| `getPropertyLocation(int $woId): ?array` | `['lat','lon']` from WO → `field_property` → `field_geofield`, or null. |

## Controller endpoints (JSON, POST, `_csrf_request_header_token`)

> **Deviation:** spec said `_csrf_token: TRUE` (query-param, path-based). Endpoints
> are JS-POST with **dynamic entry ids created mid-flow** (clock-out of a
> just-created entry), which a path-based token can't cover. Used
> `_csrf_request_header_token: 'TRUE'` — JS fetches `/session/token` and sends it
> as the `X-CSRF-Token` header (the same mechanism core REST/quickedit use).

| Route | Path | Action |
|---|---|---|
| `wo_clock.clock_in` | `/clock/in/{wo_id}` | If open entries elsewhere and no `resolved_entries` flag → `{status: intervention_required, open_entries:[…]}`; else create + `{status: clocked_in, entry:{…}}`. Intervention path prepends an audit note to the new entry. **Billed WO (Invoiced/Paid):** `clockIn()` throws → the controller catch returns `{status: error, message}` → `wo-clock.js doClockIn` else-branch shows the crew *"…closed to time entry, contact the office"* message. No entry created. |
| `wo_clock.clock_out` | `/clock/out/{entry_id}` | Ownership-checked; end=now + GPS; `{status: clocked_out}` or `{status: error}` if a guard fires. |
| `wo_clock.close_entry` | `/clock/close/{entry_id}` | `closeEntry(now, audit)`; `{status: closed, remaining_open:[…]}`. |
| `wo_clock.close_with_time` | `/clock/close/{entry_id}/time` | Body `end_time` = a **datetime-local** value `Y-m-dTH:i` (site tz) — so an entry finally closed on a *later day* records the correct date, not "today". Parsed by `resolveEndInput()` with an end-before-start guard; a bare `HH:MM` is still accepted from older cached clients (resolved against today/start day via `resolveEndTimestamp()`). `{status: closed, remaining_open:[…]}`. |

**Auth:** all routes require `access content`. **uid is taken from the session,
never the request body**, so a crafted POST can't clock in for someone else.
Clock-out/close enforce ownership unless the caller has `administer eck entities`
(office/admin correction bypass).

## Block rendering states (WoClockBlock / `_wo_clock_build_render()`)

The block detects the WO from the route + current user. The same render helper is
also invoked inline on the WO page (`hook_ENTITY_TYPE_view` for `work_order`),
which is what actually replaces the flag toggle. Cache contexts `['user','route']`,
max-age 0.

- **State A** — no open entries anywhere → `[ Clock In ]`.
- **State B** — clocked in on THIS WO → "Clocked in at … (elapsed)" + `[ Clock Out ]`.
- **State C** — open entries on OTHER WO(s) → amber alert region (per entry:
  property **linked to that WO** — opens in a new tab, "ago", start,
  **Close now** / **Close at specific time**) above a `[ Clock In on this WO ]`
  button. "Close at specific time" opens a **`datetime-local` picker** prefilled
  to the entry's start date and capped at now, so a forgotten clock-in closed a
  day or two later records the right date. The same per-entry row markup backs
  both the on-page alert and the JS recovery modal (`entryRowHtml`).

## GPS capture + silent fallback

JS requests `navigator.geolocation.getCurrentPosition()` with a **5-second
timeout**. Grant, deny, or timeout **all proceed** to the AJAX call (lat/lon
omitted when unavailable). No "you're too far away" message ever. See the
**Silent-fallback capture** pattern in `architectural_patterns.md`.

## Distance calculation on presave

`wo_clock_wo_time_clock_presave()`: when a clock-in/out location and the parent
WO's property geofield are both present, stores the Haversine distance (ft) in the
matching `*_distance_ft` field. Any lookup failure leaves the distance null and the
save proceeds — never blocks. Independent of the Phase 1 guards / wo_total_time.

## Coexistence with the flag path

`wo_timer_flag_update`, `WOLawnMowingTaskController`, and
`WOSnowRemovalTaskController` are **unchanged** — they still use `flag()`/`unflag()`
internally (Option A: no cascade refactor this phase). New entries created via
`wo_clock` are managed **independently of the flag lifecycle**: they carry no
`work_order_timer` flagging (verified), so the controllers' flag-delete cascade
simply finds nothing to delete — correct, because their end_time is set by
`clockOut()` directly. Legacy flag-managed entries keep cascading as before. The WO
view integration hides the `flag_work_order_timer` render in code (covers all 36 WO
bundles at once); the flag field + config are left intact for the cascade.

> **Deviation:** hiding the flag in `hook_ENTITY_TYPE_view` rather than editing 36
> per-bundle view-display configs — one reversible place, no 36-file config churn.

## Attribution scheme (field_source + structured notes)

Every `wo_time_clock` entry records its origin two ways: a queryable `field_source`
list marker **and** an accumulating human-readable trail in `field_notes`. This
replaces the old behavior where button clocks fell back to the `field_notes`
default of **"Manually Entered"** (the mislabel bug) — button entries now clear
that default and carry only structured notes.

**`field_source`** — `list_string`, single-value, optional, hidden on the form,
visible on the view display (weight 5). Six enum values, each written by exactly
one code path:

| Value | Meaning | Written by |
|---|---|---|
| `flag` | Legacy flag toggle | `wo_timer_flag_update` flagging_insert |
| `wo_clock_button` | Clock In/Out button (Phase A) | `WoClockService::createOpenEntry()` (clock-in) |
| `wo_clock_intervention` | Alert/modal intervention close | `WoClockService::closeEntry()` (overrides the button value — the retroactive end is now the defining attribution) |
| `manual` | Enter-Manually button / admin add form | `wo_clock_wo_time_clock_presave()` catch-all (empty source, no managed-path flag, on insert) |
| `signoff_reconciliation` | Sign-off create-missing / close-orphan | `wo_sign_off` reconciliation |
| `cleanup_script` | Data-hygiene scripts | (reserved for cleanup scripts) |
| *(NULL)* | **Legacy** — created before attribution existed | — (no backfill; check `field_notes` for context) |

**Structured note formats** (constants on `WoClockService`; timestamps are site-tz
`MM/DD/YYYY h:i AM/PM`; notes accumulate **newline-separated**, never replaced):

- Button start — `[Start: Button {ts}]`
- Button end — `[End: Button {ts}]`
- Intervention end — `[End: Intervention {resolved_end_ts} by {name}]` (stamp is the
  resolved end time, not the moment of close)
- Manual (presave) — `[Start: Manual {start_ts} by {name}]` / `[End: Manual {end_ts} by {name}]`
- Flag / sign-off: **no** new structured note — their existing human notes
  ("Start time entered through system", "[Created/Closed by … at sign-off]") stay
  as the trail; `field_source` is the structured marker.

**Sample `field_notes` accumulation:**

- Clean button in→out: `[Start: Button 07/03/2026 8:48 PM]` ⏎ `[End: Button 07/03/2026 8:48 PM]`
- Button in + intervention out: `[Start: Button …]` ⏎ `[End: Intervention 07/03/2026 7:48 PM by Jane Doe]`
- Modal auto-clock-in: `[Start: Button …]` ⏎ `[Auto-created after resolving 2 open entries. …]`
- Manual: `Manually Entered` ⏎ `[Start: Manual … by Jane Doe]` ⏎ `[End: Manual … by Jane Doe]`
- Flag: `Start time entered through system`
- Sign-off reconciliation: `[Created by Jane Doe at sign-off, …]`

**`_wo_clock_write` marker.** `WoClockService` sets a transient `$entity->_wo_clock_write
= TRUE` before saving its entries; the manual-detection presave treats an insert with
empty `field_source` and neither `_wo_clock_write` nor `_signoff_reconciliation` as a
hand save. **New rule:** every code path that creates or materially modifies a
`wo_time_clock` entry MUST stamp `field_source` (see `drupal_bos_gotchas.md`).

**Legacy queries:** treat `field_source IS NULL` as "created before structured
attribution (pre-2026-07-03) — consult notes." No backfill was performed.

## WO Hours breakdown — time-entry cards + edit modal (2026-07-09/10)

The per-teammate hours breakdown on the WO page (the `wo_hours_grouping` EVA,
which embeds `wo_time_clock_entries` per teammate via `views_field_view`) renders
each time entry as a **status card** instead of a views_aggregator table row.

- **Cards:** `wo_clock_theme()` registers `views_view_fields__wo_time_clock_entries`;
  `wo_clock_preprocess_views_view_fields()` builds card data (`_wo_clock_entry_card()`
  — start–end, hours, source label, notes, edit URL, `editable`);
  `wo_clock_preprocess_views_view()` re-adds the **per-teammate subtotal** as a
  view footer (the aggregator SUM is lost with the card style). Row template:
  `templates/views-view-fields--wo-time-clock-entries.html.twig`. CSS:
  `css/wo-hour-cards.css` (My Schedule tokens). Library `wo_clock/hour_cards`
  attached via `wo_clock_views_pre_render()` **and** `wo_clock_work_order_view()`
  (which also attaches `core/drupal.dialog.ajax`). View style flipped to
  unformatted via `web/scripts/wo_time_clock_entries_to_cards.php` (idempotent).
- **Click-to-edit modal:** for users with `update` access each card is a
  `use-ajax` modal link to `entity.wo_time_clock.edit_form` with
  `?destination=<WO alias>` — on save it redirects back to the WO and reloads,
  which recomputes `field_total_time` server-side. Crew without edit access get a
  plain, non-clickable card. Modal width `min(100%, 640px)` (mobile-safe),
  `dialogClass: wo-time-entry-dialog`.
- **Green button refresh:** `wo-clock.js` `reloadWo()` (500ms → `location.reload()`)
  after a successful clock in/out so the new entry + total appear.
- **Leaned edit form** (`web/scripts/wo_time_clock_form_leanup.php`): Teammate +
  Start + End + time-limit override visible (Teammate up top for foreman manual
  entry); Notes in a **collapsed `group_entry_notes`** group; Office Admin group
  unchanged. The multi-value Notes add button is relabeled **"Add a note"**
  (`wo_clock_form_alter`) and right-justified in the modal button pane (it's a
  `.form-actions` submit the dialog hoists next to Save/Delete).

## Migration plan

1. **Now (Phase A):** run `wo_clock` alongside the flag path; validate on live with
   real crews. Flag rendering hidden; flag mechanics still power the mowing/snow
   cascade.
2. **Later phase:** once confirmed, refactor the mowing/snow controllers off flags
   and remove `wo_timer_flag_update` + the flag field. Not in this phase.

## Validation status (local, 2026-07-03)

Backend fully validated (service, open-entry model, distance accuracy against WO
51014, guard interaction, coexistence). Browser/device-interactive steps (GPS
prompts, modal flow, visual states, timing) are coded + structurally verified but
require device testing on live. See the Phase A completion report.
