# BOS Estimate System – Thread Governance Charter

## Purpose

This document defines strict discussion boundaries for the BOS Estimate System to prevent architectural drift, cross-contamination of responsibilities, and fragmented decision-making.

Every estimating-related discussion must clearly fall into one of the domains below.

---

# 1️⃣ Estimate Module Thread

## Scope
This thread governs the **core estimating engine**.

It is responsible for:

- Rollup logic (estimate total calculation)
- Revision enforcement rules
- Phase + pricing_class behavior
- Work Order conversion logic and guardrails
- Backstop views and bulk actions
- Configuration storage (`estimate.settings`)
- Migration and retirement of legacy modules

## Explicitly NOT Allowed Here

- Landscaping-specific field decisions
- Taxonomy term design
- Scope element definitions
- Bundle-specific UI decisions

## Guiding Principle

The Estimate Module must remain bundle-agnostic and deterministic.

---

# 2️⃣ Estimate Bundle Threads (Service-Specific Bundles)

## Scope
Each thread under this category governs a specific Estimate bundle (e.g., `estimate:landscaping`, `estimate:irrigation_installation`, `estimate:lighting`, etc.).

It is responsible for:

- Bundle-specific fields for that service type
- Component selection (`field_estimate_type` or equivalent per bundle)
- Scope narrowing behavior (e.g., `field_scope_elements`, structured inputs, or other service-specific narrowing mechanisms)
- Client-facing presentation rules
- Default estimate item scaffolding for that specific bundle
- Operational flags specific to that bundle’s service domain

## Explicitly NOT Allowed Here

- Rollup implementation changes
- Global revision rules
- Core Work Order conversion architecture
- Taxonomy design decisions

## Guiding Principle

Bundle threads define behavior at the bundle layer only, regardless of service type.
They consume engine features but do not alter them.

---

# 3️⃣ Service Scope Elements Taxonomy Thread

## Scope
This thread governs the `service_scope_elements` taxonomy.

It is responsible for:

- Defining allowed scope element terms
- Determining granularity
- Hierarchy decisions (flat vs nested)
- Governance rules for terms
- Future automation potential

## Explicitly NOT Allowed Here

- Estimate bundle field configuration
- Form alter implementation details
- Work Order conversion logic
- Pricing engine decisions

## Guiding Principle

Taxonomy defines structured vocabulary only.
It does not control system logic directly.

---

# Separation of Concerns Rule

- The **Estimate Module** provides the engine.
- The **Bundle Threads** define how a specific service uses the engine.
- The **Taxonomy Threads** define vocabulary used by bundles.

No thread may redefine another thread’s authority.

---

# Conflict Resolution Rule

If a decision affects:

- All bundles → it belongs in the Estimate Module thread.
- One specific bundle → it belongs in that bundle’s thread.
- Vocabulary definitions → it belongs in the taxonomy thread.

If uncertain, default to the higher architectural layer.

---

# Canonical Documentation Order

1. Estimate Module Architecture (engine authority)
2. Bundle Specifications (implementation of engine per bundle)
3. Taxonomy Specifications (structured vocabulary)

---

# Objective

Maintain:

- Deterministic estimating logic
- Clean bundle separation
- Scalable service expansion
- Zero hardcoded IDs
- No schema drift
- No cross-module contamination

This charter ensures BOS Estimating evolves in a single, congruent architectural direction.

