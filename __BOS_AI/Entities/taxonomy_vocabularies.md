# BOS Taxonomy Vocabularies

All taxonomy vocabularies used in BOS. Base fields (name, description, weight, parent) are omitted — only custom fields are listed.

**Total vocabularies:** 43

---

## Operational — Core

### services
**Label:** Services
**Purpose:** Master service catalog. Controls which WO bundles exist, which services generate estimates, and which estimator is auto-assigned. The single source of truth for WO bundle mapping.

**Critical invariant:** `work_order.bundle` must equal `work_order.field_service.term.field_service_bundle`

| Field | Type | Label | Notes |
|---|---|---|---|
| `field_work_order_service` | boolean | Work Order Service | TRUE = this service creates WOs |
| `field_estimate_service` | boolean | Estimate Service | TRUE = this service creates estimates |
| `field_service_bundle` | string | Service Bundle | WO/estimate bundle machine name |
| `field_service_name` | string | Service Name | |
| `field_sop_code` | string | Service Code | |
| `field_default_estimator` | entity_reference → user | Default Estimator | Auto-assigned on estimate_request |
| `field_parent_service` | entity_reference → taxonomy_term | Parent Service | Hierarchical grouping |
| `field_parent_component` | boolean | Parent Component | TRUE = grouping term, not directly used |
| `field_estimate_types` | entity_reference → taxonomy_term | Allowed Estimate Types | Filters estimate type selection |
| `field_allowed_scope_elements` | entity_reference → taxonomy_term | Allowed Scope Elements | → service_scope_elements |
| `field_default_estimate_item_temp` | entity_reference_revisions | Default Estimate Item Template | |
| `field_department` | entity_reference → department | Department | |
| `field_brookstone_tags` | entity_reference → taxonomy_term | Brookstone Tags | |
| `field_description` | text_with_summary | Crew Description | |
| `field_other_names` | string | Other Search Terms or Names | |
| `field_subtitle` | string | Subtitle | |
| `field_list_order` | integer | List Order | |
| `field_home_page` | boolean | Home Page | |
| `field_home_page_slide` | image | Home Page Slide | |
| `field_banner_image` | image | Top Banner Image | |
| `field_iconic_image` | image | Iconic Image | |

### wo_status
**Label:** WO Status
**Purpose:** Work order lifecycle states. Key term IDs are hardcoded in wo_* modules.

**Hardcoded TIDs:** Complete = **1097**, In Progress = **1092**, Cancelled = **1098**

| Field | Type | Label |
|---|---|---|
| `field_old_list_number` | integer | Old List Number |

### contract_status
**Label:** Contract Status
**Purpose:** Contract lifecycle states. See `contract_status.md` for the full 12-status lifecycle.

| Field | Type | Label |
|---|---|---|
| `field_teammate_description` | text_long | Teammate Instructions |

### estimate_request_status
**Label:** Estimate Request Status
**Purpose:** Office intake workflow for estimate requests. Tracks what the office staff needs to do with the request before/during/after estimating.

**Terms:** New → Needs Info → Ready to Estimate → Estimating In Progress → Waiting on Client → Converted → Declined / Canceled

*No custom fields.*

### estimate_stage
**Label:** Estimate Stage
**Purpose:** Individual estimate lifecycle — tracks where a specific estimate is in the sales/approval process. `estimate.settings` config requires `accepted_stage_tid` and `declined_stage_tid`.

**Terms:** New → Contacted → Appointment Set → In Preparation → Under Review → Estimate Sent → Client Feedback → Pending → Accepted → Declined

*No custom fields.*

### estimate_phase
**Label:** Estimate Phase
**Purpose:** Project phasing for estimates. Allows an estimator to tag work as Phase 1/2/3, Optional, or Future Consideration — useful for large landscaping projects where the client may want to break work into stages.

> **Needs review:** Relationship to `estimate_stage` should be clarified. Phase is about *project sequencing* (which work to do first), while Stage is about *sales progression* (where the estimate is in the approval pipeline). These are orthogonal but the naming similarity may cause confusion.

**Terms:** Phase 1, Phase 2, Phase 3, Optional, Future Consideration

*No custom fields.*

---

## Operational — Snow

### snow_levels
**Label:** Snow Levels

| Field | Type | Label |
|---|---|---|
| `field_teammate_description` | text_long | Teammate Instructions |

### snow_plow_routes
**Label:** Snow Plow Routes

| Field | Type | Label |
|---|---|---|
| `field_route_name` | string | Route Name |
| `field_assigned_teammate` | entity_reference → user | Assigned Teammate |
| `field_assigned_equipment` | entity_reference → equipment | Assigned Equipment |
| `field_city` | entity_reference → city | City |

### snow_plows
**Label:** Snow Plows
**Purpose:** Plow types/sizes. `field_calculation_factor` is a placeholder intended to help calculate coverage area per push based on plow size. Not yet wired into pricing logic.

| Field | Type | Label |
|---|---|---|
| `field_calculation_factor` | decimal | Calculation Factor |
| `field_teammate_description` | text_long | Teammate Instructions |

---

## Operational — Spraying / Chemical

### carrier
**Label:** Spraying Carrier
**Purpose:** The liquid base mixed with chemicals (water, oil, etc.). Required for Colorado state inspection records — must be documented on spray applications for compliance.

| Field | Type | Label |
|---|---|---|
| `field_teammate_description` | text_long | Teammate Instructions |

### emergence_types
**Label:** Spraying Emergence Types

| Field | Type | Label |
|---|---|---|
| `field_teammate_description` | text_long | Teammate Instructions |

### signal_words
**Label:** Signal Words
**Purpose:** Chemical safety signal words (Danger, Warning, Caution).

*No custom fields.*

### spraying_frequency
**Label:** Spraying Frequency

*No custom fields.*

### spraying_locations
**Label:** Spraying Locations

| Field | Type | Label |
|---|---|---|
| `field_applicable_services` | entity_reference → taxonomy_term | Applicable Services |
| `field_teammate_description` | text_long | Teammate Instructions |

### spraying_methods
**Label:** Spraying Methods

| Field | Type | Label |
|---|---|---|
| `field_applicable_services` | entity_reference → taxonomy_term | Applicable Services |
| `field_teammate_description` | text_long | Teammate Instructions |

### spraying_soil_moisture_levels
**Label:** Spraying Soil Moisture Levels

| Field | Type | Label |
|---|---|---|
| `field_teammate_description` | text_long | Teammate Instructions |

### spraying_stages_of_weed_growth
**Label:** Spraying Stages of Weed Growth

| Field | Type | Label |
|---|---|---|
| `field_teammate_description` | text_long | Teammate Instructions |

### spraying_wind_speed
**Label:** Spraying Wind Speed

| Field | Type | Label |
|---|---|---|
| `field_teammate_description` | text_long | Teammate Instructions |

### wind_direction
**Label:** Spraying Wind Direction

| Field | Type | Label |
|---|---|---|
| `field_teammate_description` | text_long | Teammate Instructions |

### weed_categories
**Label:** Weed Categories

| Field | Type | Label |
|---|---|---|
| `field_teammate_description` | text_long | Teammate Instructions |

---

## Operational — Irrigation / Sprinkler

### irrigation_check_up_frequency
**Label:** Irrigation Check Up Frequency

*No custom fields.*

### system_complexity
**Label:** System Complexity

| Field | Type | Label |
|---|---|---|
| `field_teammate_description` | text_long | Teammate Description |

### system_operation
**Label:** System Operation

*No custom fields.*

---

## Operational — Scheduling / Frequency

### mowing_frequency
**Label:** Mowing Frequency

*No custom fields.*

### season
**Label:** Season
**Purpose:** Defines which services are available in which seasons. `field_services_performed` links to `services` terms. Used in entity reference views to limit service selection by season (e.g., fertilizing is Spring/Summer/Fall only — not Winter).

**Terms:** Pre-season, Spring, Summer, Fall, Winter

| Field | Type | Label |
|---|---|---|
| `field_services_performed` | entity_reference → taxonomy_term | Services Performed |
| `field_icon` | image | Icon |

### dump_location
**Label:** Dump Location
**Purpose:** Physical dump sites for material disposal.

| Field | Type | Label |
|---|---|---|
| `field_address` | address | Address |
| `field_location` | geofield | Location |
| `field_phone_number` | telephone | Phone Number |
| `field_website` | link | Website |
| `field_gate_code` | string | Gate Code |
| `field_list_order` | integer | List Order |
| `field_teammate_description` | text_long | Teammate Instructions |

---

## Equipment / Material Classification

### equipment_types
**Label:** Equipment Types
**Purpose:** Equipment classification. `field_small_engine_type` flags types that use small engines (distinguishes 2-cycle vs 4-cycle engine types for maintenance tracking).

| Field | Type | Label |
|---|---|---|
| `field_common_name` | string | Common Name |
| `field_small_engine_type` | boolean | Small Engine Type |
| `field_description` | text_with_summary | Crew Description |
| `field_banner_images` | image | Top Banner Image |
| `field_main_image` | image | Main Image |
| `field_icon` | image | Icon |

### equipment_status
**Label:** Equipment Status

*No custom fields.*

### material_types
**Label:** Material Types

| Field | Type | Label |
|---|---|---|
| `field_public_description` | text_long | Public Description |
| `field_teammate_description` | text_long | Teammate Instructions |
| `field_wording_document` | file | Wording Document |

### material_tags
**Label:** Material Tags

*No custom fields.*

### supplier_types
**Label:** Supplier Types

*No custom fields.*

### rock_types
**Label:** Rock Types

| Field | Type | Label |
|---|---|---|
| `field_main_image` | image | Main Image |
| `field_teammate_description` | text_long | Teammate Instructions |

---

## Christmas / Decorations

### christmas_light_colors
**Label:** Christmas Light Colors

*No custom fields.*

### christmas_light_types
**Label:** Christmas Light Types

*No custom fields.*

---

## Landscaping / Horticulture

### deer_protection
**Label:** Deer Protection

| Field | Type | Label |
|---|---|---|
| `field_teammate_description` | text_long | Teammate Instructions |

### bloom_time
**Label:** Bloom Time

*No custom fields.*

### growth_zone
**Label:** Hardiness Zones

| Field | Type | Label |
|---|---|---|
| `field_zone_number` | integer | Zone Number |
| `field_zone_subzone` | list_string | Zone Subzone |
| `field_zone_min_temp` | integer | Zone Min Temp |
| `field_zone_max_temp` | integer | Zone Max Temp |
| `field_short_description` | text_long | Short Description |

### plant_characteristics
**Label:** Plant Characteristics

| Field | Type | Label |
|---|---|---|
| `field_characteristic_category` | list_integer | Characteristic Category |

---

## People / Crew

### crew_skills_and_certifications
**Label:** Crew Skills and Certifications
**Purpose:** Tracks certifications, skills, and training for crew members. Links to training manuals via `field_training_manual`.

**Status: Early stage.** SOP and training system is still being built out — this vocabulary will grow as the training program matures.

| Field | Type | Label |
|---|---|---|
| `field_type` | list_string | Type |
| `field_renewal` | string | Renewal |
| `field_training_manual` | entity_reference → manual | Training Manual |

---

## Estimate / Contract Support

### service_scope_elements
**Label:** Service Scope Elements
**Purpose:** Granular scope items that can be attached to services for estimate detail. Intended to filter/guide which estimate items an estimator needs to include based on the type of work (e.g., "New Installation" vs "Upgrade" vs "Repair" within Landscaping would each require different scope items).

**Status: Partially implemented.** The data model is in place — `services.field_allowed_scope_elements` links services to their valid scope elements. The UI/logic layer that uses these elements to filter or prompt the estimator during estimate creation has not yet been built.

| Field | Type | Label |
|---|---|---|
| `field_scope_code` | string | Scope Code |
| `field_internal_only` | boolean | Internal Only |
| `feeds_item` | feeds_item | Feeds item |

---

## Tagging / Classification

### brookstone_tags
**Label:** Brookstone Tags
**Purpose:** SEO and search tagging for items that have both internal and public-facing presence (e.g., materials, services). Helps with search engine ranking for dual-facing content.

| Field | Type | Label |
|---|---|---|
| `field_tag_type` | list_string | Tag Type |
| `field_short_description` | text_long | Short Description |
| `field_teammate_description` | text_long | Teammate Instructions |
| `feeds_item` | feeds_item | Feeds item |

### tags
**Label:** Tags
**Purpose:** Reserved for future public-facing article content. Not currently used in BOS operations.

*No custom fields.*

### forums
**Label:** Forums
**Purpose:** Drupal default vocabulary — never used in BOS. Candidate for removal.

| Field | Type | Label |
|---|---|---|
| `forum_container` | boolean | Container |

---

## Summary by Category

| Category | Vocabularies | Count |
|---|---|---|
| Core operational | services, wo_status, contract_status, estimate_stage, estimate_phase, estimate_request_status | 6 |
| Snow | snow_levels, snow_plow_routes, snow_plows | 3 |
| Spraying/chemical | carrier, emergence_types, signal_words, spraying_frequency, spraying_locations, spraying_methods, spraying_soil_moisture_levels, spraying_stages_of_weed_growth, spraying_wind_speed, wind_direction, weed_categories | 11 |
| Irrigation | irrigation_check_up_frequency, system_complexity, system_operation | 3 |
| Scheduling/frequency | mowing_frequency, season, dump_location | 3 |
| Equipment/material | equipment_types, equipment_status, material_types, material_tags, supplier_types, rock_types | 6 |
| Christmas | christmas_light_colors, christmas_light_types | 2 |
| Landscaping/horticulture | deer_protection, bloom_time, growth_zone, plant_characteristics | 4 |
| People/crew | crew_skills_and_certifications | 1 |
| Estimate/contract support | service_scope_elements | 1 |
| Tagging/classification | brookstone_tags, tags, forums | 3 |
| **Total** | | **43** |
