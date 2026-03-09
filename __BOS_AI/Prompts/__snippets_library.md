# BOS_AI Snippets Library

## 0) Quick Use Rules

* Paste **one** snippet at the top of a new chat + your task details.
* Add **one override keyword** (ARCHITECT / EXECUTE / FAST-PASS) to control behavior.
* Always include **DELIVERABLE** so “done” is unambiguous.

---

## 1) Standard Chat Openers

### 1.1 Architect Mode Opener (default)

```text
MODE: ARCHITECT
SYSTEM: BOS (Drupal 10 + ECK). Enforce governance and naming. Do not guess.
GOAL: <one sentence goal>
CONTEXT: <what changed / where we are>
CONSTRAINTS: <non-negotiables>
DELIVERABLE: <exact artifacts you will output>
```

### 1.2 Execute Mode Opener (no redesign)

```text
MODE: EXECUTE
RULE: Implement exactly as specified. No redesign unless it is broken/unsafe.
INPUTS: <links/snippets/files>
DELIVERABLE: <exact final artifacts>
```

### 1.3 Fast-Pass Opener (minimal explanation, still correct)

```text
MODE: FAST-PASS
RULE: Minimal explanation. Provide only commands/files/patches needed.
DELIVERABLE: <exact final artifacts>
```

### 1.4 Exploration Opener (brainstorm allowed)

```text
MODE: EXPLORE
RULE: Brainstorm options. Do not enforce until I say "DESIGN FREEZE".
GOAL: <what we’re trying to achieve>
OUTPUT: Recommend one approach and why.
```

---

## 2) Override Keywords (drop these at top of any message)

### 2.1 Single-line overrides

```text
ARCHITECT: challenge assumptions; redesign if needed; correctness first.
EXECUTE: do exactly what I asked; no redesign; only fix broken parts.
FAST-PASS: minimal explanation; ship-ready output only.
RED FLAG: hunt risks/edge cases/tech debt; block bad approaches.
LOCK IT: treat decisions in this message as authoritative going forward.
PARKING LOT: capture extra ideas but do not pursue unless I ask.
```

### 2.2 Conflict handling

```text
If you see conflicting requirements, stop and present: conflict + recommended resolution.
```

---

## 3) Deliverable Contracts (prevents drift)

### 3.1 Stop if unclear

```text
DELIVERABLE CONTRACT: If any requirement is ambiguous, stop and ask targeted questions before building.
```

### 3.2 No placeholders

```text
QUALITY BAR: No placeholders. No pseudo-code. Output must be production-grade and copy-paste-ready.
```

### 3.3 Patch format required

```text
OUTPUT FORMAT: Provide a unified diff patch (git apply compatible) plus final file contents if needed.
```

---

## 4) Drupal / BOS Build Snippets

### 4.1 Fix this bug

```text
MODE: ARCHITECT
BUG: <one sentence>
EXPECTED: <what should happen>
ACTUAL: <what happens>
REPRO: <steps>
ENV: Drupal 10, modules involved: <list>
EVIDENCE: <logs, stack traces, screenshots text>
DELIVERABLE: root cause + exact fix (code/config/commands) + verification steps.
```

### 4.2 Write the final module file

```text
MODE: EXECUTE
TASK: Modify module <module_name>.
INPUT: Here is the current file content (complete).
RULES: Keep style consistent. No partials. No extra features.
DELIVERABLE: Return the complete updated file content only.
```

### 4.3 Config-first change

```text
MODE: ARCHITECT
CHANGE: <what needs to change in Drupal config>
SCOPE: <entities/bundles/fields>
DEPLOY: config export/import must be clean; no drift.
DELIVERABLE: exact config items to change + YAML (if applicable) + drush commands + rollback plan.
```

### 4.4 Views build in code

```text
MODE: ARCHITECT
VIEW GOAL: <what the view must show>
USERS: <roles who use it>
FILTERS: <must-have filters/exposed filters>
SORT: <sort rules>
DISPLAY: <page/block/attachment> + path + permissions
DELIVERABLE: recommended View architecture + config export YAML identifiers + any custom hooks required.
```

### 4.5 Route / controller / form build

```text
MODE: ARCHITECT
FEATURE: <what you want>
URL/ROUTE: <path>
ACCESS: <who can use>
DATA: <entities/fields involved>
DELIVERABLE: final PHP (controller/form), routing.yml, services.yml if needed, and any libraries/twig updates.
```

### 4.6 Entity access / permissions issue

```text
MODE: RED FLAG
ISSUE: Access/permission inconsistency.
DETAILS: entity type=<>, bundle=<>, field=<>, operation=<view/edit/create/delete>
OBSERVED: <who is blocked and where>
DELIVERABLE: identify where access is denied (entity access, field access, form display, route access) + precise fix.
```

---

## 5) SOP Authoring Snippets

### 5.1 Create SOP

```text
MODE: ARCHITECT
SOP REQUEST: Create a new SOP.
SOP CODE: <OWNER-AREA-SERVICE-SEQUENCE>
BUNDLE: <governance or operational bundle>
AUDIENCE: <roles>
DELIVERABLE: Field-mapped SOP only using these fields:
- SOP Code
- Title
- SOP Type/Bundle
- Purpose
- Scope
- Rules & Responsibilities
- Prerequisites
- Steps & Procedures (Pre-Checks / Steps / Quality Checks / Completion)
- KPIs (if applicable)
- Related SOPs
- Notes/Exceptions
```

### 5.2 Update SOP without scope creep

```text
MODE: EXECUTE
TASK: Update this SOP content only. Do not change SOP Code.
INPUT: Current SOP fields (verbatim).
CHANGE REQUEST: <exact changes>
DELIVERABLE: Updated fields only (same field order), nothing else.
```

---

## 6) Debugging & Evidence Capture

### 6.1 Commands to diagnose

```text
MODE: FAST-PASS
NEED: A minimal command set to diagnose <issue>.
ENV: local DDEV vs live cPanel/SSH: <which>
DELIVERABLE: exact commands + what to look for in output.
```

### 6.2 Analyze logs

```text
MODE: ARCHITECT
INPUT: Here are logs/errors (verbatim).
CONTEXT: what I was doing when it happened.
DELIVERABLE: likely root cause ranked + exact next actions to confirm + fix options.
```

---

## 7) Data / QuickBooks / Imports

### 7.1 Transformation script

```text
MODE: ARCHITECT
DATA: CSV/Excel with columns: <list>
GOAL: <target format / one-row-per-entity / import schema>
RULES: preserve IDs; no lossy transforms unless called out.
DELIVERABLE: production-ready script + usage instructions + sample output schema.
```

### 7.2 Formula only

```text
MODE: FAST-PASS
TASK: Provide the exact Excel formula.
INPUTS: sheet name, column letters, example values.
DELIVERABLE: formula only + one-line explanation.
```

### 7.3 Import plan

```text
MODE: ARCHITECT
SOURCE: <where data is coming from>
TARGET: <Drupal entities/bundles>
KEYS: <unique keys>
DELIVERABLE: recommended approach (Feeds vs Migrate) + mapping table + error-handling strategy + rollback.
```

---

## 8) Decision Logging

### 8.1 Design freeze

```text
DESIGN FREEZE:
- Goal:
- Constraints:
- Chosen approach:
- Rejected alternatives (why):
- Done criteria:
Proceed to implementation.
```

### 8.2 Lock a governance decision

```text
LOCK IT:
Decision: <one sentence>
Scope: <where it applies>
Rationale: <2–4 bullets>
Non-goals: <what this does not change>
```

---

## 9) Code Review & Refactor Control

### 9.1 Senior review

```text
MODE: RED FLAG
TASK: Review this code/config for correctness, maintainability, security, and drift.
INPUT: <paste code>
DELIVERABLE: prioritized issues + exact patch recommendations.
```

### 9.2 Refactor proposal only

```text
MODE: ARCHITECT
TASK: Propose a refactor plan only (no code yet).
SCOPE: <file/module>
DELIVERABLE: step plan + new structure + risk notes + stopping points.
```

---

## 10) Drift Snap-Back

```text
Re-align to the control prompt: enforce standards, stop guessing, correctness-first, production-grade output only.
```
