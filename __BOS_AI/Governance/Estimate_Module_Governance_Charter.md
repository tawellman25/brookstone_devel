📘 BOS – Estimate Module Governance Charter (Authoritative v2)

This thread is the canonical domain authority for the estimate module.

It governs:

Estimate Request

Estimate entity (bundles + identity model)

Estimate Items integration (pricing engine)

Revision engine

Totals engine

Stage governance

Work Order conversion

Estimate-related validation rules

Nothing else.

1️⃣ Domain Stack (Locked)
Layer 1 — Estimate Request (Intake Container)

Role:

Opportunity container

One → Many Estimates

No bundle restrictions

No pricing logic

No revision logic

Responsibilities:

Anchor for revision chain scoping

Anchor for multi-component quoting

May be triggered externally (e.g., contract module)

Must not contain estimating logic

This belongs fully to the estimate module.

Layer 2 — Estimate (Component Quote)

Bundle-driven entity.

Responsibilities:

Service/component identity

Revision chain enforcement

Stage lifecycle

Work Order linkage

Total value holder (never manually entered)

Revision Chain Scope:

estimate_request_id + field_estimate_type

Only one estimate in a chain may have:

field_is_current_revision = TRUE

Bundles:

Represent construction-level service domains

Must not represent pricing types

Must align to Work Order bundles where possible

Must remain stable once in production

Classification Model:

Bundle = construction domain

field_estimate_type = specific service/component (taxonomy term)

Layer 3 — Estimate Items (Pricing Engine)

Bundles:

labor

materials

equipment

subcontractor

Responsibilities:

Calculate line totals

Apply markup

Respect pricing_class rules

Feed rollup engine

Single Source of Truth:

estimate.field_estimate_total =
  SUM(estimate_items.field_line_total
      WHERE field_estimate = estimate_id
      AND pricing_class = included)

No manual totals. Ever.

2️⃣ Engines Owned by Estimate Module

Revision Enforcement Engine

Totals Rollup Engine

Work Order Conversion Engine

Stage Gating Engine

Validation Layer (including Scope Code uniqueness)

No hardcoded taxonomy IDs.
All stage references stored in estimate.settings.

3️⃣ Validation Standard

All estimate-related validation lives in estimate.

Includes:

Scope code uniqueness constraint

Vocabulary: service_scope_elements

Field: field_scope_code

Normalized (trim + uppercase)

Optional empty allowed

Exclude current TID on update

Attached via hook_entity_bundle_field_info_alter

Server-side enforced.

4️⃣ Explicit Non-Scope (Not Discussed Here)

Contract lifecycle logic

Work Order internal lifecycle rules

SOP design

QuickBooks integration

Services taxonomy design outside of estimate identity

UI/UX debates unrelated to module logic

If conversation drifts, we branch.

5️⃣ Thread Rule

From this point forward:

This thread is the single authoritative Estimate Module thread.

We do not:

Re-define the domain stack

Re-open structural decisions

Re-scope revision logic

Re-debate bundle purpose

We build forward.

✅ Direct Answer to Your Question

Yes.

From this point on:

I do not need to search for prior structure.

I will treat this charter as locked.

All future module decisions will reference this model.

Any deviation will be explicitly flagged before proceeding.

We are now operating from a stable foundation.
