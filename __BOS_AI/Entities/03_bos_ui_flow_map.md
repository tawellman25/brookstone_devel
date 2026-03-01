# BOS UI Flow Map (What Users Click)

This map shows how BOS is typically used in the UI by role.
It is “click-flow first” (screens and actions), not data-model first.

Legend:
- → = typical next click/action
- (Admin) = admin-facing UI
- (Crew) = crew-facing UI
- (Office) = office/admin staff UI
- (Public) = public website pages
- [creates] = creates a new record
- [links] = links an existing record
- [updates] = updates existing record

---

## 1) Public Website Flow (Public)

Services Landing Page(s)
→ Service Category (e.g., Landscape & Lawn Care)
→ Specific Service Page (e.g., Weekly Lawn Mowing)
→ Contact / Request Estimate / Call / Form

Notes:
- Public Services pages are driven by Services taxonomy.
- Many public services are grouping pages (not Work Order services).

---

## 2) Office: Property-Centric Flow (Office)

Properties List / Search
→ Property Detail Page
  - shows Property Nickname prominently
  - shows contacts, gate code, notes, maps, service eligibility
→ Actions from Property:
  - View Work Orders (filtered to this Property)
  - View Contracts (filtered to this Property)
  - Create Work Order (manual)
  - Start workflow (service-specific modules may add buttons)
  - Add/View Notes, Photos, Maps

Common UI intent:
- Staff find a property first, then decide what needs done.

---

## 3) Office: Contract Build Flow (Office)

Contracts List / Search
→ Contract Detail
  → Add Contract Sections [creates]
    - choose Contract Section bundle (client-friendly)
    - select Service (field_service)
      - selection list filters to “Work Order Service = TRUE”
    - fill service-specific scope/estimate fields
  → Activate Contract (status change)

Contract Section editing:
Contract → Contract Section
  - budget/estimate fields
  - “last year” indicators
  - client notes
  - optional links to Work Orders (multi-stage services)

Notes:
- Contract Sections define intent and scope.
- They should not contain actual execution history.

---

## 4) Scheduling Flow (Office + Crew Leaders)

Scheduling View(s) / Calendar / Lists
→ Filter by:
  - Service
  - Status
  - Date range
  - Zipcode/Area
  - Crew leader / Supervisor
→ Assign / Schedule Work Orders [updates]
→ Crew sees assigned work in daily view

Key UI expectation:
- Scheduling is driven from Work Orders (execution), not Contracts (intent).
- Contracts influence what Work Orders exist and what fields are required.

---

## 5) Crew: Daily Execution Flow (Crew)

Daily Route / Assigned Work Orders
→ Open Work Order
  - see Property Nickname + address + gate codes + call-ahead + notes
  - see Work To Be Done (scope)
  - see materials/chemicals/equipment expectations (if present)
→ Start work / track execution:
  - Time Clock entries [creates] (wo_time_clock)
  - Task checklist [updates] (wo_tasks_list)
  - Add Notes [creates] (wo_notes / comments)
  - Add Photos/Attachments [creates]
  - Record Materials Used [creates] (wo_material_list + items)
  - Record Chemicals Used [creates] (wo_chemicals_used)
  - Record Spraying Conditions [creates] (wo_spraying_conditions, if used)
  - Record Equipment/Rentals [creates] (wo_rental_equipment, if used)
→ Mark complete / sign-off [updates]
→ Status updates logged [creates] (wo_status_updates)

Post-completion rule:
- Work Orders and child records become read-only except admin corrections.

---

## 6) Billing / Office Closeout Flow (Office)

Completed Work Orders View
→ Verify:
  - time totals
  - materials/chemicals subtotals
  - trip/dump/rental totals
  - billing notes
→ Mark invoiced / printed flags [updates]
→ Export / sync to accounting system (process varies)

Key UI rule:
- Billing totals must be stable after invoicing/export.
- Snapshot pricing (materials/chemicals/equipment) prevents retroactive changes.

---

## 7) Common “Find Paths” (How users locate things)

### Find a Property
Search (address / nickname / client)
→ Property
→ Work Orders / Contracts / Notes

### Find a Contract
Search by client/property
→ Contract
→ Contract Sections

### Find Work Orders
Work Orders list
→ filter by:
  - status
  - service
  - date range
  - area/zipcode
  - crew/supervisor
→ open Work Order

### Find Materials/Chemicals/Equipment
Catalog pages (internal)
→ search by name
→ view supplier/manufacturer/docs
→ used during WO entry (stocked selection)
→ snapshots taken on Work Order usage

---

## 8) Admin / Governance Flow (Admin)

Configuration & Data Integrity
→ Manage Services taxonomy
  - Work Order Service flag
  - Work Order bundle mapping
→ Manage lookup/reference entities:
  - Status vocab/entities
  - Suppliers, Manufacturers
  - Zipcode entities (URLs/areas)
→ Audit:
  - missing bundle mappings
  - invalid WO service selections
  - integrity exceptions

---

## 9) UI Guardrails (Expected Behaviors)

- Property Nickname must be visible everywhere crews interact with a Work Order.
- Contract Sections must require Service selection (WO services only).
- Work Orders must require:
  - Property
  - Service
  - Status
- Completed Work Orders lock editing except admin corrections.
- Snapshot pricing prevents historical totals from changing.

