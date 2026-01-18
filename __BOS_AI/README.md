# BOS – Brookstone Operating System

## Purpose
BOS (Brookstone Operating System) is the internal operations platform for Brookstone Outdoors LLC.

BOS exists to:
- Centralize operational, client, property, and work order data
- Enforce business rules through system design, not human memory
- Provide a single source of truth for operations, training, and governance

BOS is **not** an ERP in user-facing language.
BOS may integrate with accounting systems (e.g., QuickBooks), but does not replace them.

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
- Files in `/bos-ai/` are authoritative
- Code must conform to these documents
- If code conflicts with these rules, the conflict must be surfaced
- Do not invent entities, bundles, or rules not defined here

## Scope
This repository defines:
- Entities and their relationships
- Governance and access boundaries
- Operational lifecycles (Work Orders, SOPs, etc.)

Anything not defined here must be explicitly added before implementation.
