# BOS Governance — Equipment System & SOP Integration
# Project: Brookstone Equipment

---

## PURPOSE

This governance document defines how the Brookstone Equipment system is designed, discussed, and converted into Standard Operating Procedures (SOPs) within BOS.

This exists to ensure:
- equipment decisions are architected before being documented
- SOPs are derived from validated system behavior
- BOS structure, governance, and operations remain aligned
- no procedural drift or duplicate logic is introduced

---

## SCOPE

Applies to:
- all equipment-related discussions and decisions
- all Equipment entity usage (`equipment`)
- all SOPs derived from equipment operations
- all contributors (owners, managers, AI, staff)

Includes:
- fleet (vehicles, trailers)
- heavy equipment
- small engines
- tools, sprayers, attachments
- equipment usage in Work Orders
- maintenance, inspections, repair, replacement, and lifecycle governance

Does NOT apply to:
- material consumption (handled by material entities)
- supplier logic (handled by supplier + material_supplier entities)

---

## SYSTEM ARCHITECTURE (AUTHORITATIVE LAYERS)

The Brookstone Equipment system operates under a **three-layer governance model**:

### Layer 1 — Governance (Behavior Control)
Defined by:
- `GOV-SOP-001 — SOP Authoring Standard`

Purpose:
- defines how SOPs must be written
- enforces structure, language, and output order
- governs authored procedural content only

---

### Layer 2 — Schema (Structure Control)
Defined by:
- `sop.md` (SOP entity)
- `equipment.md` (Equipment entity)

Purpose:
- defines what data exists in BOS
- defines field behavior and boundaries
- separates authored content from metadata

Key enforcement:
- authored SOP fields vs system-managed fields are strictly separated
- equipment fields define asset behavior, not SOP behavior

---

### Layer 3 — System Behavior (Execution Control)
Defined by:
- Drupal (ECK entities)
- custom modules
- workflows and validation logic

Purpose:
- enforces lifecycle, validation, and system rules
- ensures data integrity and operational consistency

---

## CORE PRINCIPLE

> SOPs must describe a system that is already defined — not invent one.

Equipment operations must be:
1. architected
2. validated
3. governed
4. THEN converted into SOPs

---

## EQUIPMENT SYSTEM AUTHORITY

The Equipment system is governed by:

- `equipment.md` — authoritative definition of:
  - bundles
  - fields
  - identifiers
  - costing structure
  - status rules
  - invariants

Reference:
`__BOS_AI/Entities/equipment.md`

Key rules:
- equipment is an **asset**, not a consumable
- equipment must be uniquely identifiable
- costing and rate fields must be stable when used for Work Orders
- equipment lifecycle must be controlled via status and dates, not deletion

---

## SOP SYSTEM AUTHORITY

The SOP system is governed by:

- `sop.md` — defines SOP entity structure
- `GOV-SOP-001` — defines SOP authoring rules

References:
- `__BOS_AI/Entities/sop.md`
- `__BOS_AI/Governance/GOV-SOP-001-SOP-Authoring-Standard.md`

---

## SOP AUTHORING BOUNDARY

Only the following fields are authored in SOP creation:

1. SOP Code  
2. SOP Title  
3. SOP Type (Bundle)  
4. Purpose  
5. Scope  
6. Rules & Responsibilities  
7. Prerequisites  
8. Steps & Procedures  
9. Key Performance Indicators  
10. Related SOPs  

All other SOP fields:
- are system-managed
- are metadata
- are workflow-controlled
- must NOT be included unless explicitly required by a defined BOS workflow

---

## EQUIPMENT → SOP CONVERSION MODEL

All SOPs in this project must follow this pipeline:

### Phase 1 — System Design
- define the operational process
- identify constraints, rules, and ownership
- align with Equipment entity structure

### Phase 2 — Governance Validation
- confirm process is enforceable
- confirm no conflicts with existing systems
- confirm required data exists in BOS

### Phase 3 — SOP Creation
- convert validated process into SOP
- follow GOV-SOP-001 exactly
- assign correct SOP bundle
- define parent/child relationships where applicable

---

## SOP CLASSIFICATION RULES

SOPs derived from equipment must be placed in the correct bundle:

| Use Case | SOP Bundle |
|--------|-----------|
| Equipment maintenance | `maintenance` |
| Safety procedures | `safety` |
| Office/admin processes | `office_administration` |
| System workflows | `system_procedures` |
| Field execution | service-specific bundles |
| Training | `training` |

Incorrect bundle usage is not allowed.

---

## PARENT / CHILD SOP STRUCTURE

### Parent SOPs
- define:
  - scope
  - rules
  - governance
- do NOT contain deep execution steps

### Child SOPs
- define:
  - execution steps
- must reference parent SOP
- must assume parent rules

---

## EQUIPMENT GOVERNANCE AREAS (MANDATORY)

The following areas must be defined before SOP creation:

- equipment identification and numbering
- equipment status lifecycle
- inspection processes
- maintenance and preventative maintenance
- repair decision and approval rules
- replacement and capital decision rules
- equipment usage in Work Orders
- attachment compatibility and relationships
- key control and access
- cost and rate governance

No SOP may be created for an undefined area.

---

## PROHIBITED BEHAVIOR

The following are not allowed:

- writing SOPs for undefined processes
- mixing system design and SOP output in the same artifact
- including metadata fields in SOP authoring output without defined workflow
- creating duplicate or overlapping SOPs
- bypassing parent/child structure
- storing operational notes in SOP body (must use `sop_log`)

---

## INTEGRATION WITH OTHER ENTITIES

Equipment governance must align with:

- Work Orders (equipment usage and costing)
- Suppliers (external sourcing only — no overlap)
- Materials (consumables only — no overlap)

Clear separation of responsibility must be maintained.

---

## COMPLIANCE

This governance is considered enforced when:

- all equipment processes are defined before SOP creation
- all SOPs follow GOV-SOP-001 exactly
- all SOPs align with `sop.md` field boundaries
- all equipment usage aligns with `equipment.md` invariants

Non-compliance results in:
- SOP rejection
- rework required before implementation

---

## SYSTEM PRIORITY

1. Governance SOPs  
2. Entity schema (`sop.md`, `equipment.md`)  
3. System implementation  

If conflicts occur, resolve in this order.

---

## STATUS

- Governance: Active
- Enforcement: Required
- Scope: Project-wide (Brookstone Equipment)

---