# contract_sections_ui

UI-only module for editing and reviewing `contract_sections` in the context of a `Contract`, without touching BOS data models, pricing, or audit logic.

This module exists to provide a **sane admin UX** while keeping BOS architecture clean and enforceable.

---

## Purpose

`contract_sections_ui` is responsible for **how staff interact with Contract Sections**, not for:

- data rules
- pricing / costing
- audit creation
- synchronization logic

Those concerns remain in:

- `contract_residential` / contracts modules (rules + sync)
- `contract_sections_audit` (append-only audit logging)

---

## Responsibilities

### What this module DOES

- Provides **modal editing** of `contract_sections` from a Contract page
- Supports **two UI patterns**:
  1. Legacy multi-block / EVA section layouts (no page reload)
  2. Admin single-table layout (refresh on save)
- Controls **return / redirect behavior** after save
- Hides destructive or confusing UI elements:
  - Delete button
  - “Add another” widget buttons
- Provides a **History modal** for viewing audit entries for a single section

### What this module DOES NOT do

- Create or suppress audit records
- Enforce business rules
- Modify pricing or costing
- Change BOS entity schemas
- Recalculate historical data

---

## Routes

### Edit Section (Modal)

**Path**
```
/contracts/{contracts}/sections/{contract_sections}/edit-dialog
```

**Route name**
```
contract_sections_ui.section_edit_dialog
```

**Behavior**
- Opens the section edit form in a modal
- Guards that the section belongs to the contract
- Uses standard entity form rendering
- Save behavior depends on UI mode

---

### History Modal (Audit Viewer)

**Path**
```
/contracts/{contracts}/sections/{contract_sections}/history-dialog
```

**Route name**
```
contract_sections_ui.section_history_dialog
```

**Behavior**
- Opens a modal showing **audit history for one section**
- Renders a Views display from `contract_sections_audit_log`
- Expects the View display to accept **Contract Section ID** as a contextual filter
- Read-only; no data mutation

---

## Two Supported UI Modes

### 1) Legacy EVA / Multi-Block UI (Client-style layout)

**Used when**
- Contract page shows many individual section blocks
- Each block has its own Edit link

**How it works**
- Edit links include `csui_display=block_#`
- Module intercepts save with AJAX
- Re-renders **only the affected block**
- No page reload
- Scroll position preserved

**Example Edit link**
```html
<a href="/contracts/123/sections/456/edit-dialog?csui_display=block_7"
   class="use-ajax"
   data-dialog-type="modal"
   data-dialog-options='{"width":"80%"}'>
  Edit
</a>
```

This mode exists for backwards compatibility and client-facing layouts.

---

### 2) Admin Table UI (Recommended)

**Used when**
- Contract Admin view mode shows **one consolidated table**
- One row per `contract_section`

**How it works**
- Edit links **do NOT** include `csui_display`
- Edit still opens in modal
- Save performs **normal redirect** using `destination`
- Contract page refreshes
- All calculated fields and audit summaries update reliably

**Example Edit link**
```html
<a href="/contracts/123/sections/456/edit-dialog?destination=/contracts/123"
   class="use-ajax"
   data-dialog-type="modal"
   data-dialog-options='{"width":"80%"}'>
  Edit
</a>
```

This is the preferred pattern for **Admin**.

---

## History Link (Audit Modal)

Use anywhere sections are listed:

```html
<a href="/contracts/123/sections/456/history-dialog"
   class="use-ajax"
   data-dialog-type="modal"
   data-dialog-options='{"width":"80%"}'>
  History
</a>
```

**Behavior**
- Opens a modal
- Shows full audit history for that section
- Does not interfere with edit/save flow

---

## Layout Strategy

### Client View Mode
- Uses EVA / multi-block section displays
- Document-style presentation
- No admin-only operational UI

### Admin View Mode
- Uses **Layout Builder**
- Contains:
  - Contract summary
  - **One “Contract Sections Admin Table” View**
- No EVA
- No per-section attachments
- Faster, simpler, maintainable

Global Blocks are intentionally avoided for Admin to prevent visibility leakage.

---

## Audit Design Notes

- Audit creation is handled entirely in `contract_sections_audit`
- Audit must be triggered by **entity lifecycle** (entity update), not:
  - form submit handlers
  - redirects
  - route names
  - AJAX vs non-AJAX requests
- `contract_sections_ui` never creates or suppresses audit records

Audit UI in this module is **read-only**.

---

## Why This Module Exists Separately

This separation prevents:

- UI decisions from corrupting data rules
- audit logic from depending on request context
- future refactors from breaking history

It also allows:

- client UI and admin UI to evolve independently
- reuse of modal patterns elsewhere in BOS

---

## Summary

`contract_sections_ui` is a **workflow and UX module**.

It:
- makes section editing sane
- keeps admin fast and reliable
- avoids BOS rule contamination
- supports both legacy and modern layouts

The complexity here replaces much worse hidden complexity with **explicit, controlled behavior**.
