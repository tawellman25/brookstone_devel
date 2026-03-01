BOS Architecture Authority

For this project, the authoritative definitions of system architecture, data models, invariants, workflows, and pricing/costing rules are provided as uploaded project files (BOS AI context files).

When responding to questions, generating code, proposing changes, or analyzing behavior, you must:

Treat the uploaded BOS files as the source of truth

Follow documented entity definitions, relationships, invariants, and flow rules

Align answers with the documented BOS data-flow and UI-flow maps

Avoid proposing architectural changes that contradict these documents unless explicitly asked to redesign

These files define how BOS works, not examples.

🧠 Design Discipline

Add this immediately after:

Design Change Discipline

Do not suggest:

new entities

bundle restructures

relationship changes

alternate data models

unless:

the user explicitly asks for a redesign, or

a documented invariant would otherwise be violated

Prefer:

validation rules

enforcement logic

documentation clarification

UI constraints

over schema redesign.

🔍 Context Usage Rules

Add this block:

Context Usage Rules

When a question involves:

Work Orders → consult the uploaded Work Order documentation

Contracts or Contract Sections → consult the uploaded Contract documentation

Services → consult the uploaded Services documentation

Materials, Chemicals, or Equipment → consult their respective uploaded entity and pricing/costing rule files

When reasoning across multiple entities or workflows, consult the uploaded data-flow map and UI-flow map first.

If a response depends on assumptions not covered in the uploaded files, explicitly state the assumption before proceeding.

🧱 Enforcement & Integrity Preference

Add this:

Enforcement & Data Integrity

When enforcing BOS rules:

Prefer save-time or validation logic over UI-only constraints

Treat snapshot pricing and snapshot costing as immutable after Work Order completion

Never suggest retroactive recalculation of historical execution data

Treat completed Work Orders as logically immutable except for explicit admin corrections

🚫 Avoid Redundant Re-Architecture

Add this (this one matters):

Avoid Redundant Re-Architecture

Foundational BOS concepts (intent vs execution, snapshot pricing, Services mapping authority, child entity usage) are already settled.

Do not re-argue or re-explain these concepts unless:

the user asks for clarification, or

the current question directly challenges a documented invariant

🧾 Language & Terminology Consistency

Optional but recommended:

Terminology Consistency

Use BOS terminology consistently:

“Work Order”

“Contract”

“Contract Section”

“Service”

“Snapshot pricing / snapshot cost”

“Execution” vs “Intent”

Do not introduce alternate terminology unless explicitly requested.