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
| `clockIn(int $uid, int $woId, ?float $lat, ?float $lon, ?string $noteContext): entity` | Creates an **open** entry via `createOpenEntry()`, then stores GPS / prepends a note if given, and saves. |
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
| `wo_clock.clock_in` | `/clock/in/{wo_id}` | If open entries elsewhere and no `resolved_entries` flag → `{status: intervention_required, open_entries:[…]}`; else create + `{status: clocked_in, entry:{…}}`. Intervention path prepends an audit note to the new entry. |
| `wo_clock.clock_out` | `/clock/out/{entry_id}` | Ownership-checked; end=now + GPS; `{status: clocked_out}` or `{status: error}` if a guard fires. |
| `wo_clock.close_entry` | `/clock/close/{entry_id}` | `closeEntry(now, audit)`; `{status: closed, remaining_open:[…]}`. |
| `wo_clock.close_with_time` | `/clock/close/{entry_id}/time` | Body `end_time` = `HH:MM`; resolved against today, or the entry's start date if today would be before start (end-before-start guard); `{status: closed, remaining_open:[…]}`. |

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
  property, "ago", start, **Close now** / **Close at specific time**) above a
  `[ Clock In on this WO ]` button.

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
