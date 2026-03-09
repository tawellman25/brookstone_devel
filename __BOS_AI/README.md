# BOS – Brookstone Operating System

## Purpose
BOS (Brookstone Operating System) is the internal operations platform for Brookstone Outdoors LLC.

BOS exists to:
- Centralize operational, client, property, and work order data
- Enforce business rules through system design, not human memory
- Provide a single source of truth for operations, training, and governance

BOS is **not** an ERP in user-facing language.
BOS may integrate with accounting systems (e.g., QuickBooks), but does not replace them.

## Directory Structure

| Directory | Contents |
|---|---|
| `Entities/` | Entity type specifications: fields, bundles, relationships, invariants |
| `Modules/` | Custom module architecture: hooks, services, business logic |
| `Governance/` | Design charters, authority models, bundle specifications |
| `Rules/` | Business rules: pricing, costing, identity |
| `Prompts/` | AI interaction guides and prompt templates |
| `Mappings/` | Field label mappings and cross-references |
| `automation/` | Automation specifications (e.g., check-up generator) |
| `drush_commands/` | Custom Drush command documentation |
| `Archive/` | Retired/historical documents (not authoritative) |

### Key Entry Points

- **Start here:** `Entities/00_core_entities.md` — the operational spine
- **Entity policy:** `Entities/01_entities_policy.md` — rules for all entity decisions
- **Data flow:** `Entities/02_bos_data_flow_map.md` — how data moves through BOS
- **UI flow:** `Entities/03_bos_ui_flow_map.md` — user workflows
- **Module tiers:** `Modules/01_modules_tier_policy.md` — contrib module governance
- **WO modules:** `Modules/wo_bundle_modules.md` — the wo_* module pattern

## Platform
- Drupal 10 (Drupal 11 compatible)
- Heavy use of custom modules
- Heavy use of ECK (Entity Construction Kit)
- Minimal reliance on contrib modules unless justified

## Core Principles
- The **User entity is the system hub**
- Business rules belong in code, not policy documents alone
- Governance is enforced by structure, not trust
- Explicit > implicit
- Predictable > clever

## Architectural Rules
- Custom modules are preferred over contrib when logic is BOS-specific
- Access control must be explicit and testable
- Entity relationships must be intentional and documented
- Deletion is dangerous and must be controlled
- Automation must never create hidden side effects

## Naming & Language
- “BOS” is the authoritative system name
- “ERP” may appear only in technical or administrative documentation
- Field, bundle, and module names must follow BOS naming standards
- SOP Codes are immutable once approved

## AI Usage Rules
- Files in `__BOS_AI/` are authoritative
- Code must conform to these documents
- If code conflicts with these rules, the conflict must be surfaced
- Do not invent entities, bundles, or rules not defined here

## Scope
This repository defines:
- Entities and their relationships
- Governance and access boundaries
- Operational lifecycles (Work Orders, SOPs, etc.)

Anything not defined here must be explicitly added before implementation.
