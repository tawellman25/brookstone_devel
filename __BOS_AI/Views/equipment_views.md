# BOS Views — Equipment Management

## Dashboard Views

### Equipment Command Board
- View ID: `equipment_command_board`
- Path: `/admin/operations/equipment`
- Menu: Operations > Equipment
- Base table: equipment_field_data (all bundles, exposed Type filter)
- Columns: #, Equipment, Type, Status, Miles/Hrs, Cond, Risk, Defects, Last Inspected, Decision
- Default sort: equipment number ASC
- Access: administrator, site_admin, administration, supervisor

### Inspection Review Queue
- View ID: `equipment_inspection_review`
- Path: `/admin/operations/equipment/inspection-review`
- Menu: Operations > Equipment > Inspection Review
- Base table: equipment_inspection_field_data
- Filter: field_review_status = pending
- Columns: Equipment, Type, Date, Inspector, Safe?, Note, View
- Default sort: inspection date DESC
- Access: administrator, site_admin, administration, supervisor

### Open Defects Board
- View ID: `equipment_open_defects`
- Path: `/admin/operations/equipment/open-defects`
- Menu: Operations > Equipment > Open Defects
- Base table: equipment_defect_field_data
- Filter: status IN (open, scheduled, in_repair, deferred)
- Columns: Equipment, Category, Severity, Reported, Summary, Assigned, Status, View
- Default sort: severity DESC, date ASC
- Access: administrator, site_admin, administration, supervisor

### Repair vs Replace Board
- View ID: `equipment_repair_replace`
- Path: `/admin/operations/equipment/repair-replace`
- Menu: Operations > Equipment > Repair vs Replace
- Base table: equipment_field_data (exposed Type filter)
- Columns: Equipment, Type, Miles/Hrs, Open Defects, Resale, Cond, Engine, Trans, Decision
- Default sort: condition score ASC
- Access: administrator, site_admin, administration

---

## EVA Displays (on all equipment entity pages)

### Equipment Inspections
- View ID: `equipment_inspections_eva`
- Contextual filter: field_equipment target ID
- Columns: Date, Type, Inspector, Safe?, Review, View
- Sort: inspection date DESC

### Equipment Defects
- View ID: `equipment_defects_eva`
- Contextual filter: field_equipment target ID
- Columns: Reported, Category, Severity, Summary, Status, View
- Sort: date reported DESC

### Equipment Maintenance History
- View ID: `equipment_maintenance_eva`
- Contextual filter: field_equipment target ID
- Columns: Date, Type, Vendor, Cost, Verified, View
- Sort: event date DESC

---

## Key Differences from Fleet Version
- Command Board has exposed Type filter (works for all equipment, not just vehicles)
- Repair vs Replace has exposed Type filter
- EVA displays attach to ALL equipment bundles (not just vehicles)
- Inspection Review shows bundle type column (vehicles, mowers, etc.)
- Open Defects shows Assigned To column

---

Created: April 2026
