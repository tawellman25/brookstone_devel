# TimeTrax Integration vs Replacement — Strategic Decision

**Date:** 2026-04-26
**Status:** Active. Reviewed at the triggers in Section 5.

## 1. The decision

BOS will integrate with TimeTrax v5 for variance reporting via direct SQL read. BOS will **not** replace TimeTrax as the system of record for compensable hours and the QuickBooks payroll feed. This decision is reviewed at the triggers listed in Section 5.

## 2. What was considered

Four options were on the table. They are listed below with rough sizing — none of these numbers were measured, they are working estimates used to rank the options against each other.

| Option | Effort | Ongoing cost | Business value | Primary risk |
|---|---|---|---|---|
| 1. Replace with custom BOS-native time clock + payroll integration | 6–10 months of dev | Internal maintenance only | High — full control of the workflow, mobile/field punching, GPS, deep WO integration | Building a payroll-feed system is high-stakes plumbing; bugs go straight to people's paychecks |
| 2. Replace with third-party SaaS (Workyard, QuickBooks Time, etc.) | 1–3 months migration during slow season | $2,400+/year per Brookstone's headcount | Medium — solves field punching and GPS but doesn't deepen WO integration | Migration must happen in a narrow window; vendor lock-in; ongoing cost forever |
| 3. **Integrate with TimeTrax via SQL read (chosen)** | 2–4 weeks for the foundation + import module | None beyond what TimeTrax already costs | Medium — unlocks variance dashboards and live data inside BOS | TimeTrax remains a single-PC failure point; integration breaks if Pyramid changes the schema |
| 4. Status quo — manual biweekly export | None | Office-staff time biweekly | Low — TimeTrax data exists but doesn't reach BOS in usable form | Operational variance stays invisible; we keep flying without a labor-cost gauge |

## 3. Why integration won

Custom replacement is six to ten months of focused work on a problem Pyramid has already solved and that is not part of Brookstone's competitive moat. Payroll plumbing is also the kind of project where bugs go straight to people's paychecks — a fair risk for a differentiating system, but timekeeping is not one. TimeTrax works today.

Third-party SaaS solves the same problem TimeTrax already solves, with a forever-recurring subscription and a migration window that has to land during slow season. Without variance data to show whether the change is worth $2,400-plus a year, the move is premature. We don't yet know what we'd be buying.

Integration achieves roughly 80% of the value at roughly 10% of the cost. The thing we actually want — a real-time variance dashboard inside BOS comparing compensable hours to WO labor hours — only requires that punch data flow from TimeTrax into BOS on a regular cadence. The static analysis (TimeTrax_Hack/ANALYSIS.md) showed that TimeTrax v5 is a standard ASP.NET application on SQL Server Express with credentials in plaintext in `Web.config`. That makes a read-only SQL agent on the office PC genuinely clean rather than a hack — and the build-vs-buy calculus tipped toward integration the moment we understood the architecture.

## 4. What was deliberately not solved

This decision leaves several real problems unsolved and they should be named honestly. Crews still drive to the shop to punch in and out, which costs unbilled travel time at both ends of the day. There is no GPS verification that crews were at the property they claim to have worked. TimeTrax remains a single-PC failure point — if the office PC dies, payroll workflow stops until it is rebuilt. TimeTrax's payroll approval gate continues to control the payroll workflow, so that workflow can't be redesigned without going around or through Pyramid's UI. There is no way to clock into a specific Work Order from the field; clock-in is to the day, not to the job.

These costs were considered and accepted. The integration approach makes them visible — variance dashboards will quantify the field-vs-shop-presence gap for the first time — but it does not fix any of them. If the variance data eventually shows the gap is large enough to justify replacement, that is exactly when this decision should be revisited.

## 5. Revisit triggers

Reconsider if any of the following occurs:

1. Brookstone opens a second physical location. A single TimeTrax PC in Delta doesn't scale to multi-location operations and the calculus changes immediately.
2. Variance data (after six months of flow) shows annual labor loss from shop-clock-vs-field-presence gaps above a specific dollar threshold. The threshold itself is to be set after the data exists; setting it now would be guessing.
3. An apprentice matures to the point of being able to own a payroll module build end-to-end. The opportunity cost of custom replacement collapses when Todd is not the bottleneck.
4. The TimeTrax PC fails catastrophically and rebuilding it would take meaningful time. Migration may be cheaper than restoration at that point, and the moment is forced.
5. Pyramid significantly changes TimeTrax's architecture or pricing in a way that breaks the SQL integration or makes it cost-prohibitive.

## 6. Foundation already built

The BOS-side foundation supports this direction without locking to TimeTrax specifically. The `time_clock_entry` entity was activated in Phase 1A (commit `9dc58e68`) with `field_source` designed to accept multiple origins (`live_punch`, `manually_entered`, `timetrax_import`, `system_correction`). The user-side mapping fields — `field_time_clock_id` (a vendor-neutral string) and `field_time_clock_system` (an extensible list, currently `timetrax` only) — were chosen so adopting Workyard, QuickBooks Time, or a future BOS-native clock would not require renaming or migrating any existing field. Phase 1A.1 (commit `dd4088f8`) added the `bos_user_time_clock_mapping` module to scope those fields to teammates only. Phase 1B (commit `7fca532e`) added `field_external_punch_id` for idempotent imports from any source, not just TimeTrax.

If a future revisit decides to replace TimeTrax, only the import module changes. The entity, user mapping, role gating, and idempotency contract all remain useful.

## 7. Related documents

- [`__BOS_AI/Entities/time_clock_entry.md`](../Entities/time_clock_entry.md) — entity definition, fields, idempotency pattern.
- `TimeTrax_Hack/ANALYSIS.md` — static analysis of TimeTrax v5's internals, including the SQL connection details, MVC/WCF surface, and the rationale for choosing direct SQL read over HTML scraping or log-file tail. Note: that folder is gitignored and lives only on the dev machine.
- *(Future)* `__BOS_AI/Modules/timetrax_integration_plan.md` — tactical implementation plan for the SQL agent and the BOS receive endpoint. To be written separately.
