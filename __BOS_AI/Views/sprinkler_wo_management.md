# Sprinkler WO Management View

**Path:** `/admin/properties/sprinkler-systems/wo-management`
**View ID:** `admin_sprinkler_start_up_management`
**Display:** `page_1` (Page) + `attachment_1` (Geofield Map)
**Base table:** `work_order_field_data`

## Purpose

Centralized management dashboard for sprinkler-related Work Orders. Allows office staff, supervisors, and crew to filter, review, and access WOs across all sprinkler service types with an embedded map showing geographic distribution.

## WO Bundles Included

| Bundle | Service |
|---|---|
| `sprinkler_check_up` | Check Up |
| `sprinkler_design` | Design |
| `sprinkler_installation` | Installation |
| `sprinkler_repair` | Repair |
| `sprinkler_start_up` | Start Up |
| `sprinkler_winterizing` | Winterizing |

## Displayed Columns

| Column | Source |
|---|---|
| # | Row counter |
| Work Order link | Entity link to WO edit page |
| Property | `work_order.field_property` reference |
| Work To Do Description | `work_order.field_work_todo_description` |
| Total Zones | `property_sprinkler_system.field_total_zones` |
| Complexity | `property_sprinkler_system.field_complexity_level` |
| Operation | `property_sprinkler_system.field_operation` |
| System Type | `property_sprinkler_system.field_system_type` |
| Scheduled Date/Time | `scheduling.field_scheduled_date_and_time` |
| Scheduled | `work_order.field_scheduled` (boolean) |
| UID | Creator/owner |
| Created | WO creation timestamp |
| Status | `work_order.field_status` (taxonomy reference) |
| Geofield | Geographic location map marker |

Hidden fields used internally: ID, Title, Service, Property Nickname.

## Exposed Filters

| Filter | Field | Type | Notes |
|---|---|---|---|
| Type | WO bundle | Multi-select dropdown | Sprinkler bundles only; "Select All/None" |
| Status | `field_status` | Multi-select dropdown | Open, Needs Confirmed, Scheduled, Assigned, Needs Parts, Parts Ordered, In Progress, Needs Access, Waiting for Customer Response |
| Complexity | `property_sprinkler_system.field_complexity_level` | Multi-select dropdown | system_complexity taxonomy; "Select All/None" |
| Property Nickname | `properties.field_nickname` | Text (exact match) | Hidden |
| Street Address | `properties.field_street_address` | Text | Hidden |
| City | `properties.field_city_name` | Text | Hidden |
| Created Date | `work_order.created` | Date range | Pre-set to current year |

Filter form uses **Better Exposed Filters (BEF)** with `text_input_required` mode (must click Apply).

## Relationships / Joins

```
work_order
├── → properties (via field_property)
│     └── → property_sprinkler_system (via reverse field_property_system_info)
├── → scheduling (via reverse field_work_order)
│     └── → assigned user (via field_assigned_to)
└── → wo_status taxonomy (via field_status)
```

## Map Attachment

The `attachment_1` display renders a **Geofield Map** showing WO locations:
- Marker theming by WO bundle type (different icons per sprinkler service)
- Marker clustering enabled (Overlapping Marker Spiderfier)
- Custom map styling: stroke color black, fill color blue, opacity 0.1

## Access Control

**Role-based access:**
- `administrator`
- `site_admin`
- `administration`
- `supervisor`
- `teammates`

## Admin Menu Location

```
Admin → Properties → Sprinkler Systems → WO Management
```

Sibling pages under Properties:
- Admin Mow Crew Route
- Admin Property Estimating
- Admin Property Fertilizing Info
- Admin Property Spraying Info
- Admin Snow Removal
- Admin Turf Sq Footage
- Admin Weed Spray Route
