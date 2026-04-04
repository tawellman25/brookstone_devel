# BOS Views — Fleet Management

## Dashboard Views

### Fleet Command Board
- View ID: `fleet_command_board`
- Path: `/admin/operations/fleet`
- Menu: Operations > Fleet
- Base table: equipment_field_data (vehicles bundle)
- Columns: Truck #, Vehicle, Status, Mileage, Condition, Risk, Open Defects, Last Inspected, Decision
- Default sort: Truck Number ASC
- Access: administrator, site_admin, administration, supervisor

### Inspection Review Queue
- View ID: `fleet_inspection_review`
- Path: `/admin/operations/fleet/inspection-review`
- Menu: Operations > Fleet > Inspection Review
- Base table: fleet_inspection_field_data
- Filter: field_review_status = pending
- Columns: Truck #, Date, Vehicle, Inspector, Source, Safe?, Warnings, Note, View
- Default sort: Truck Number ASC
- Access: administrator, site_admin, administration, supervisor

### Open Defects Board
- View ID: `fleet_open_defects`
- Path: `/admin/operations/fleet/open-defects`
- Menu: Operations > Fleet > Open Defects
- Base table: fleet_defect_field_data
- Filter: field_defect_status IN (open, scheduled, in_repair, deferred)
- Columns: Truck #, Vehicle, Category, Severity, Reported, Summary, Repeat, Est. Cost, OOS, Status
- Default sort: Truck Number ASC, then severity DESC
- Access: administrator, site_admin, administration, supervisor

### Repair vs Replace Board
- View ID: `fleet_repair_replace`
- Path: `/admin/operations/fleet/repair-replace`
- Menu: Operations > Fleet > Repair vs Replace
- Base table: equipment_field_data (vehicles bundle)
- Columns: Truck #, Vehicle, Mileage, Open Defects, Resale Value, Condition, Engine, Trans, Decision
- Default sort: Truck Number ASC
- Access: administrator, site_admin, administration

---

## EVA Displays (on equipment:vehicles entity pages)

### Vehicle Inspections
- View ID: `fleet_vehicle_inspections`
- Contextual filter: field_vehicle target ID = vehicle entity ID
- Columns: Date, Inspector, Safe?, Review Status, Odometer, View
- Sort: inspection date DESC

### Vehicle Defects
- View ID: `fleet_vehicle_defects`
- Contextual filter: field_vehicle target ID = vehicle entity ID
- Columns: Reported, Category, Severity, Summary, Status, View
- Sort: date reported DESC

### Vehicle Maintenance History
- View ID: `fleet_vehicle_maintenance`
- Contextual filter: field_vehicle target ID = vehicle entity ID
- Columns: Date, Type, Vendor, Cost, Mileage, View
- Sort: event date DESC

---

Created: April 2026
