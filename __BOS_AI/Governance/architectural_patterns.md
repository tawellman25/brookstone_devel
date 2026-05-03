# BOS Architectural Patterns

Patterns established during Phase 0.5 / Phase 1 / Pre-Phase-2 / Phase 2 work (commits `1493fdca` through `76c19117`, March–May 2026). Future contributors implementing similar concerns should adopt these patterns rather than reinventing.

This document captures the WHY for each pattern so deviations are intentional, not accidental.

---

## Threshold values: business_setting with fallback constants

**When:** A module needs a tunable numeric or boolean threshold that may legitimately vary across deployments or over time.

**Pattern:**

1. Add the threshold field to `business_setting` config_pages
2. Module reads the field via `@config_pages.loader` at runtime
3. Module retains a private `const` as the fallback default if config returns null/empty
4. The field's docblock or help text identifies which module(s) consume it

**Example — `field_long_shift_hours`** (Phase 0.5, commit `1493fdca`):

```php
private const HOURS_LONG = 16.0;

private function getLongShiftHours(): float {
  $cfg = $this->configPagesLoader->load('business_setting');
  if (!$cfg || !$cfg->hasField('field_long_shift_hours') || $cfg->get('field_long_shift_hours')->isEmpty()) {
    return self::HOURS_LONG;
  }
  $value = $cfg->get('field_long_shift_hours')->value;
  return is_numeric($value) ? (float) $value : self::HOURS_LONG;
}
```

**Why this pattern:**

- **Avoids module dependency inversion.** `wo_total_time` (foundational) needs the long-shift threshold for its form-layer validate handler; `bos_teammate_operations` (analytics) also needs it for `AnomalyDetectionService::open_stale`. Putting the constant in `bos_teammate_operations` and having `wo_total_time` import it would invert the natural layering. Putting it in `business_setting` lets both consume it without depending on each other.
- **Operational tunability.** Site admins can adjust thresholds via the UI without a code deploy.
- **Resilience.** Fallback constants ensure the system stays functional if business_setting is unreachable (e.g., during DB recovery, mid-cim).

**When NOT to use:**

- Constants that should never change (e.g., taxonomy term IDs hardcoded into business logic). Those stay as private consts.
- Per-entity values (e.g., per-property pricing). Those belong on the entity itself.

---

## Cross-module signaling: entity context flags

**When:** A module needs to signal across hooks (or across module boundaries) within a single save flow, where the signal applies only to one in-progress operation and shouldn't persist.

**Pattern:**

- Set a property on the entity instance with a leading underscore prefix and descriptive name
- The property is consumed within the same save flow by another hook or guard
- Document scope explicitly in the property's docblock or comments — what each flag bypasses, what it does NOT bypass
- Set during legitimate operations only; never set as a permanent property

**Examples (all in `wo_sign_off`):**

| Flag | Set on | Bypasses |
|---|---|---|
| `_signoff_reconciliation` | wo_time_clock entry | Phase 1 guard 4 (Invoiced/Paid lock) — NOT guard 5 (Canceled WO) |
| `_signoff_reconciliation_in_progress` | wo_complete_info or wo_tasks_list parent | The defense-in-depth presave guard during the reconciliation submit handler's own save |
| `_reconciled_closed_ids` / `_reconciled_created_ids` | wo_complete_info parent (new entities only) | Stash for `hook_entity_insert` to populate audit fields after parent has an ID |

**Why this pattern:**

- **Lightweight cross-hook coordination** without inventing a service or KV-store entry for a per-save-flow signal
- **Visible at code-review time** — properties on the entity show up in any handler that inspects the entity, easier to trace than KV-store reads
- **Per-flow scope** — the property dies with the entity instance after save; no risk of stale state poisoning future operations

**Naming convention:**

- Leading underscore (Drupal convention for transient/internal properties)
- Descriptive name with verbed state where applicable (`_in_progress`, `_signoff_reconciliation`)
- Bypass scope documented in the consuming guard's comments, NOT just at the set site

**When NOT to use:**

- Information that needs to persist beyond the current save (use a real field)
- Information shared across requests (use KV-store, state API, or session)
- Information that crosses entity instances within the same request (use service-level static cache, not entity property)

---

## Defense-in-depth: form-layer + presave-layer

**When:** A module enforces a business rule that needs to apply uniformly across all save paths (form, REST, VBO, programmatic, migration).

**Pattern:**

- **Form-layer validate handler** provides friendly UX for normal users. Inline error messages, points at specific form fields, accommodates "soft" warnings with confirmation flows.
- **Defense-in-depth presave guard** catches programmatic / REST / VBO / migration writes that bypass the form layer. Throws `EntityStorageException` with a clear message.
- **Both layers reference shared helpers/constants** so they cannot drift. If the rule changes, you change one helper.
- **Programmatic bypass legitimacy** is signaled via context flags (above pattern).

**Examples:**

- **Phase 1 wo_time_clock guards** ([`wo_total_time.module`](../Modules/wo_total_time.md)): five presave guards (end<start, future start, future end, WO Invoiced/Paid lock, WO Canceled lock). Form-layer mirror in `_wo_total_time_form_validate` for guards 1-3; soft `>field_long_shift_hours` confirmation flow at the form layer only.
- **Phase 2b wo_complete_info reconciliation** ([`wo_sign_off.module`](../Modules/wo_sign_off.md)): form-layer reconciliation fieldset + validate + submit, plus `_wo_sign_off_assert_roster_complete()` defense-in-depth at the top of `wo_sign_off_entity_presave`.
- **Phase 2c wo_tasks_list:lawn_mowing reconciliation** (same module): same form-layer pattern, plus `wo_sign_off_wo_tasks_list_presave()` defense-in-depth, plus a hard validation handler on `field_mowing_who_on_site` that runs FIRST in the form's #validate chain.

**Why this pattern:**

- **Form layer is for UX**, presave layer is for invariant enforcement. They serve different purposes; both are needed.
- **Programmatic save paths exist** — REST, Views Bulk Operations, migrations, queue processors. The form layer doesn't run for any of them.
- **Single source of truth** — both layers call the same helper functions, so the business rule definition lives in one place.

**When NOT to use:**

- Pure UX hints (placeholder text, descriptions) — those are form-layer only.
- Database-level constraints already enforced (e.g., `NOT NULL`, foreign keys) — Drupal's entity validation handles these already.
- Rules that legitimately only apply to specific contexts (e.g., "office staff can override X") — use permission checks rather than context flags.

---

## Audit field population: inline vs `hook_entity_insert`

**When:** A module records an audit reference to a parent entity that's being saved in the same save flow (e.g., "this child entity was modified by sign-off X").

**Pattern:**

Two cases, two strategies:

**Case A — Parent entity exists already (edit operation):**

- Set audit field inline during the pre-save submit handler
- `$entity->id()` is populated; reference works directly
- Save the child entity within the submit handler with the audit field set

**Case B — Parent entity is new (insert operation):**

- During pre-save submit handler: do the audit-affecting work (close orphans, create entries) BUT skip the audit field set (parent ID is NULL)
- Stash IDs of affected entities as temporary properties on the parent (`_reconciled_closed_ids`, `_reconciled_created_ids`)
- Implement `hook_entity_insert` for the parent type that reads the stashed arrays and populates audit fields in a second save pass per affected entity

**Both paths:** use the appropriate context flag (e.g., `_signoff_reconciliation`) to bypass guards on the audit-update saves.

**Examples:**

- **Phase 2b** ([`wo_sign_off.module`](../Modules/wo_sign_off.md)): wo_complete_info reconciliation. New-entity stash via `_reconciled_*_ids` + `wo_sign_off_entity_insert()` second pass.
- **Phase 2c**: wo_tasks_list:lawn_mowing reconciliation. ALWAYS existing entity (created earlier by `WOLawnMowingTaskController` workflow start), so inline-only — no stash needed.

**Why this split:**

- **Inline saves are simpler** when ID is available. No reason to add an extra hook + property if you don't have to.
- **`hook_entity_insert` is the only hook that fires after parent ID assignment** for new entities. Pre-save handlers and form submit handlers both run before. This is the right hook for "do something with the new ID."
- **Stash via entity property** is the lightest cross-hook signaling mechanism (see context flag pattern above).

**When the second-pass approach gets ugly:**

- If the audit-affecting work is large (many entities), the second pass doubles the save cost.
- If the audit field is critical and operations downstream of the save read it, the second pass is too late — use a different architecture (e.g., create the parent first as a stub, then do reconciliation, then finalize).

---

## Service abstraction for cross-bundle resolvers

**When:** The same conceptual question needs different answers across bundles or entity types — e.g., "who's on the crew for this work order?" varies by sign-off entity type.

**Pattern:**

- Create a service with explicit routing logic
- Public class constants for the in-scope bundle lists (so consumers can also reference them for early returns)
- Single source of truth — every consumer asks the service, never reads the underlying fields directly
- Future bundle additions update the service's constants and routing, not every consumer

**Example — `WoCrewRosterService`** ([`wo_sign_off.md`](../Modules/wo_sign_off.md)):

```php
final class WoCrewRosterService {
  public const COMPLETE_INFO_BUNDLES = [
    'complete', 'landscape_crew', 'clean_up_crew',
    'fertilizing_crew', 'irrigation_crew', 'spray_crew',
  ];
  public const TASKS_LIST_BUNDLES = ['lawn_mowing'];

  public function getCrewForWorkOrder(int $wo_id, string $signoff_entity_type, string $signoff_bundle): array {
    if (!$this->isInScope($signoff_entity_type, $signoff_bundle)) return [];
    // Routes to field_those_on_crew or field_mowing_who_on_site based on type
    ...
  }

  public function isInScope(string $signoff_entity_type, string $signoff_bundle): bool {
    return match ($signoff_entity_type) {
      'wo_complete_info' => in_array($signoff_bundle, self::COMPLETE_INFO_BUNDLES, TRUE),
      'wo_tasks_list' => in_array($signoff_bundle, self::TASKS_LIST_BUNDLES, TRUE),
      default => FALSE,
    };
  }
}
```

**Why this pattern:**

- **Hides the field-name routing** behind a clean API. Consumers don't care that field_those_on_crew exists on six bundles and field_mowing_who_on_site exists on one — they ask for "the roster" and get it.
- **Forward compatibility.** When snow_removal or special_mowing eventually joins Phase 2's scope, only the service constants change. Form alters, presave guards, and dashboards all keep working.
- **Testable in isolation.** Service can be unit-tested without form rendering or entity save flows.

**When NOT to use:**

- Single-bundle, single-field reads. A service is overkill for `$entity->get('field_X')->target_id`.
- Reads that don't need to respect a bundle scope. If "the crew roster" is a universal concept across all bundles, just use the field directly.

---

## Form-alter rebuild behavior decisions

**When:** A form alter injects content that depends on the values of other form fields (e.g., a reconciliation fieldset whose contents depend on the crew roster the user is editing).

**Three behaviors to choose between:**

| Behavior | Mechanism | Pros | Cons |
|---|---|---|---|
| **A** | Render only at submit time | Simplest implementation; no AJAX | Worst UX — user can't see what's required until they try to submit |
| **B** | Render on initial form build, no reaction to changes | Safe default; predictable | Stale display if user changes dependencies after initial load — validate handler must catch at submit |
| **C** | AJAX rebuild on dependency change | Best UX | Fragile across browsers/Drupal versions; entity_reference_autocomplete + #ajax interactions are particularly unreliable |

**Decision factors:**

- **Connectivity reliability of target users.** Office staff on desktops tolerate AJAX round-trips well. Field crews on tablets over LTE often don't.
- **Complexity of dependency change.** A simple text input is easier to AJAX-listen on than a multi-value entity_reference autocomplete with "Add another item" buttons.
- **Risk tolerance for stale display.** If the user MUST see the latest state before they can act correctly, AJAX is worth the fragility. If they just need to be RIGHT at submit time, validate-at-submit suffices.

**Examples:**

- **Phase 2b wo_complete_info reconciliation**: hybrid — Behavior B initial render + explicit "Refresh reconciliation list" button (Behavior C-lite, deliberate user action triggers rebuild). Avoids `autocompleteclose` event fragility while still giving office staff a way to update without submitting.
- **Phase 2c wo_tasks_list:lawn_mowing reconciliation**: Behavior B only. Field-tablet usage; AJAX unreliable. Validate handler always re-categorizes at submit; if roster changed since initial render, an error directs the user to reload the page.

**Always: validate handler is the safety net.**

Whichever behavior you pick, the form's validate handler must always re-evaluate the dependency at submit time. Behavior A doesn't make sense without it; Behaviors B and C both rely on it as defense for the "user changed dependency after rendering" case.

**When to consider deferring the upgrade to true Behavior C:**

- Field usage data shows the manual-action friction is operationally meaningful
- The injected content is small enough that AJAX round-trip cost is bounded
- Test coverage for the AJAX path exists (or can be added)

If those don't hold, stick with Behavior B + safety-net validate. Don't ship Behavior C just because it's the "best" option in the abstract.

---

## Status

- Created: 2026-05-02 (Phase 2 retrospective documentation pass)
- Living document — add new patterns as they emerge from project work, with the surfacing commit cited.
