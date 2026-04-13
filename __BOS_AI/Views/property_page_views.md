# BOS Views — Property Page Tabs & UI

## Overview

The Property detail page (`/properties/{id}`) uses Views page displays as tabs. Each view attaches as a menu tab with a configurable weight controlling left-to-right order.

**Base route:** `/properties/{properties_id}`

---

## Tab Order

| Weight | Tab | View ID | Path |
|---|---|---|---|
| 1 | Overview | (default entity view) | `/properties/{id}` |
| 2 | Contracts | `property_contracts` | `/properties/{id}/contracts` |
| 5 | Ownership | `property_ownership_records` | `/properties/{id}/ownership_records` |
| 6 | — | (reserved) | — |
| 7 | Estimates | `property_estimates` | `/properties/{id}/estimates` |
| 8 | Work Orders | `property_work_orders` | `/properties/{id}/work-orders` |

---

## Property Work Orders

- **View ID:** `property_work_orders`
- **Base table:** `work_order_field_data`
- **Tab weight:** 8
- **Access:** administrator, site_admin, administration, supervisor, teammates
- **Pager:** full, 50 items per page
- **Argument:** `field_property_target_id`

**Filters:**
- Created date range (defaults to current year: `{YYYY}-01-01` to `12-31`)
- Service (taxonomy, exposed select)
- Status (taxonomy, exposed select)

**Fields:** View link, Created/By, Title (with work description), Status (with completion date)

---

## Property Contracts

- **View ID:** `property_contracts`
- **Base table:** `contracts_field_data`
- **Tab weight:** 6
- **Access:** administrator, site_admin, administration, supervisor, teammates
- **Pager:** none
- **Argument:** `field_property_target_id`

**Fields:** Title (linked), Type

**Footer buttons (compact inline):**
- `+ Commercial` → `/admin/content/contracts/add/commercial?...`
- `+ Residential` → `/admin/content/contracts/add/residential?...`
- `+ Snow Removal` → `/admin/content/contracts/add/snow_removal?...`

Button style: `button button--primary button--small` in a `<div class="property-contract-buttons">` wrapper.

---

## Property Estimates

- **View ID:** `property_estimates`
- **Base table:** `estimate_field_data`
- **Tab weight:** 7
- **Access:** administrator, site_admin, administration, supervisor, teammates
- **Pager:** full, 25 items per page
- **Argument:** `field_property_target_id` (via `field_estimate_request` relationship)

**Filters (exposed):** Type (bundle), Stage (taxonomy)

**Fields:** Title (linked to `/estimate/{id}`), Type, Stage, Assigned To, Estimate Total, Created

**Footer:** `+ Add Estimate Request` button with property_id pre-filled

---

## Property Ownership Records

- **View ID:** `property_ownership_records`
- **Base table:** `properties_field_data`
- **Tab weight:** 5
- **Access:** administrator, site_admin, site_assistant, administration, supervisor, teammates
- **Pager:** none
- **Argument:** `id` (properties.id)
- **Relationship:** `reverse__ownership_record__field_property_reference`

**Fields:** Property, Last Known Owner, Entered, By

**Header:** `+ New Ownership` button with property_id and destination pre-filled

---

## Create Work Order Dropdown (Block)

- **Block ID:** `property_work_order_links`
- **Plugin:** `Drupal\properties\Plugin\Block\PropertyWorkOrderLinksBlock`
- **Location:** Property detail page (all `/properties/{id}` paths)
- **Visibility:** Route-based — only on `entity.properties.canonical` and subpaths

**Behavior:**
- Renders a `<select>` dropdown with all work_order bundles (excludes legacy `estimate` bundle)
- Bundles loaded dynamically from `entity_type.bundle.info` service (not hardcoded)
- "Create" button opens the WO add form in a new tab with `field_property` pre-filled
- URL pattern: `/admin/content/work_order/add/{bundle}?edit[field_property][widget][0][target_id]={property_id}`

**Cache:** No caching (contexts: route, url.path)

---

## Deleted Views

| View ID | Reason |
|---|---|
| `property_sprinkler_info` | Removed (functionality consolidated elsewhere) |

---

Created: April 2026
