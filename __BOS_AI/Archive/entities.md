# BOS Entities (Authoritative)

This file defines the canonical BOS entities and relationships.
If an entity/relationship is not listed here, it does not exist.

---

## System Hub

### user (core)
Purpose:
- Authentication + roles
Primary relationships:
- 1:1 profile
Rules:
- User is the system hub. Operational data does not live here.

### profile (module: profile)
Bundle(s):
- [list your profile types]
Owner:
- user (required, 1:1)
Purpose:
- Identity + preferences + external references (e.g. QuickBooks)
Key fields:
- [field names you consider canonical]
Rules:
- Profiles never exist without a user.

---

## Locations / Assets

### properties (ECK)
Bundle(s):
- property
Purpose:
- Physical locations where work happens
Key fields:
- [address field, geo fields, etc.]
Relationships:
- property -> ownership records (if any)
- property -> contacts (field_contacts, field_primary_contact_ref)
Rules:
- Properties persist across ownership changes.

### contact (entity type?)
Bundle(s):
- [if applicable]
Purpose:
- Non-user people attached to profiles/properties
Relationships:
- contact -> profile (optional)
- contact -> property (optional)
Rules:
- Contacts are not users.

---

## Operations

### work_order (ECK)
Bundle(s):
- [list your WO bundles: mowing, pruning, sprinkler_start_up, snow_removal, etc.]
Purpose:
- Execution record for work
Required relationships:
- work_order -> property (required)
- work_order -> client/profile (if you have it)
Key fields (global):
- status/state
- scheduled date/time
- total time
- notes
- materials/chemicals
Rules:
- Completed work orders are immutable (or define your rule).
- Deletion is controlled.

### contract (custom module? entity type?)
Bundle(s):
- [if applicable]
Purpose:
- Agreement that drives WOs and billing
Relationships:
- contract -> profile/client
- contract -> property (one or many)
Rules:
- Contracts do not perform work; WOs do.

### estimate (custom)
Purpose:
- pre-work pricing + scope
Relationships:
- estimate -> property
- estimate -> profile/client
Rules:
- estimates can become work orders (if true)

---

## Governance / Knowledge

### sop (ECK)
Bundle(s):
- Governance
- [others]
Purpose:
- authoritative procedures and rules
Rules:
- SOP codes are immutable once approved.
- Parent SOPs define scope; child SOPs inherit.

---

## Supporting

### equipment (custom)
Purpose:
- asset tracking + maintenance
Relationships:
- equipment -> work_order (optional)
Rules:
- equipment is not consumed.

### material (custom)
Purpose:
- consumables
Relationships:
- material -> work_order (optional)
Rules:
- materials may be required on invoices/compliance.

---

## Cross-Cutting Rules

### Ownership
- Define: which entity “owns” which other entity (User/Profile/Property)

### Deletion
- Define: what may be deleted, who can delete, and what must be archived instead

### Source of Truth
- Accounting is downstream; BOS is authoritative for operational truth
- External IDs allowed; external logic not allowed


    [0] => ai_prompt
    [1] => ai_prompt_type
    [2] => ai_agent
    [3] => block
    [4] => block_content
    [5] => block_content_type
    [6] => block_visibility_group
    [7] => captcha_point
    [8] => corresponding_reference
    [9] => comment_type
    [10] => comment
    [11] => computed_field
    [12] => config_pages_type
    [13] => config_pages
    [14] => contact_form
    [15] => contact_message
    [16] => eck_entity_type
    [17] => eck_entity_bundle
    [18] => editor
    [19] => entity_browser
    [20] => feeds_subscription
    [21] => feeds_feed
    [22] => feeds_feed_type
    [23] => feeds_import_log
    [24] => field_config
    [25] => field_storage_config
    [26] => file
    [27] => filter_format
    [28] => flag
    [29] => flagging
    [30] => geocoder_provider
    [31] => image_style
    [32] => job_schedule
    [33] => key_config_override
    [34] => key
    [35] => media_type
    [36] => media
    [37] => media_bulk_config
    [38] => menu_link_content
    [39] => migration_group
    [40] => migration
    [41] => modal
    [42] => modeler_api_data_model
    [43] => module_builder_module
    [44] => node_type
    [45] => node
    [46] => page
    [47] => page_variant
    [48] => path_alias
    [49] => profile
    [50] => profile_type
    [51] => redirect
    [52] => responsive_image_style
    [53] => rest_resource_config
    [54] => rules_component
    [55] => rules_reaction_rule
    [56] => search_page
    [57] => shortcut
    [58] => shortcut_set
    [59] => smart_date_format
    [60] => mailer_policy
    [61] => mailer_transport
    [62] => action
    [63] => menu
    [64] => system_readiness
    [65] => taxonomy_term
    [66] => taxonomy_vocabulary
    [67] => taxonomy_menu
    [68] => tour
    [69] => user_role
    [70] => user
    [71] => pathauto_pattern
    [72] => xmlsitemap
    [73] => view
    [74] => paragraphs_type
    [75] => paragraph
    [76] => entity_form_mode
    [77] => entity_view_mode
    [78] => entity_view_display
    [79] => entity_form_display
    [80] => date_format
    [81] => base_field_override
    [82] => address
    [83] => address_type
    [84] => chemical
    [85] => chemical_type
    [86] => city
    [87] => city_type
    [88] => classification
    [89] => classification_type
    [90] => client_app
    [91] => client_app_type
    [92] => client_type
    [93] => client_type_type
    [94] => contacts
    [95] => contacts_type
    [96] => contracts
    [97] => contracts_type
    [98] => contract_sections
    [99] => contract_sections_type
    [100] => county
    [101] => county_type
    [102] => crew_types
    [103] => crew_types_type
    [104] => department
    [105] => department_type
    [106] => employment
    [107] => employment_type
    [108] => equipment
    [109] => equipment_type
    [110] => equipment_check_in_out
    [111] => equipment_check_in_out_type
    [112] => equipment_status_update
    [113] => equipment_status_update_type
    [114] => estimate
    [115] => estimate_type
    [116] => estimate_items
    [117] => estimate_items_type
    [118] => estimate_notes
    [119] => estimate_notes_type
    [120] => handbook
    [121] => handbook_type
    [122] => lawn_and_garden_pests
    [123] => lawn_and_garden_pests_type
    [124] => manual
    [125] => manual_type
    [126] => manufacturer
    [127] => manufacturer_type
    [128] => material
    [129] => material_type
    [130] => material_suppliers
    [131] => material_suppliers_type
    [132] => ownership_record
    [133] => ownership_record_type
    [134] => phone_number
    [135] => phone_number_type
    [136] => positions
    [137] => positions_type
    [138] => properties
    [139] => properties_type
    [140] => property
    [141] => property_type
    [142] => property_christmas_decor
    [143] => property_christmas_decor_type
    [144] => property_fertilizing_info
    [145] => property_fertilizing_info_type
    [146] => property_instructions
    [147] => property_instructions_type
    [148] => property_landscape_details
    [149] => property_landscape_details_type
    [150] => property_lawn_maintenance
    [151] => property_lawn_maintenance_type
    [152] => property_snow_removal_info
    [153] => property_snow_removal_info_type
    [154] => property_spraying_info
    [155] => property_spraying_info_type
    [156] => property_sprinkler_design
    [157] => property_sprinkler_design_type
    [158] => property_sprinkler_info
    [159] => property_sprinkler_info_type
    [160] => property_sprinkler_pumps
    [161] => property_sprinkler_pumps_type
    [162] => property_sprinkler_system
    [163] => property_sprinkler_system_type
    [164] => property_ss_sources
    [165] => property_ss_sources_type
    [166] => property_ss_zones
    [167] => property_ss_zones_type
    [168] => property_system_controller
    [169] => property_system_controller_type
    [170] => property_zone_watering_time
    [171] => property_zone_watering_time_type
    [172] => scheduling
    [173] => scheduling_type
    [174] => site_content
    [175] => site_content_type
    [176] => site_landing_page
    [177] => site_landing_page_type
    [178] => sop
    [179] => sop_type
    [180] => sprinkler_system_types
    [181] => sprinkler_system_types_type
    [182] => sprinkler_types
    [183] => sprinkler_types_type
    [184] => sq_ft_break_points
    [185] => sq_ft_break_points_type
    [186] => state
    [187] => state_type
    [188] => supplier
    [189] => supplier_type
    [190] => testimonial
    [191] => testimonial_type
    [192] => time_clock_entry
    [193] => time_clock_entry_type
    [194] => work_order
    [195] => work_order_type
    [196] => wo_chemicals_used
    [197] => wo_chemicals_used_type
    [198] => wo_complete_info
    [199] => wo_complete_info_type
    [200] => wo_estimate_notes
    [201] => wo_estimate_notes_type
    [202] => wo_material_dumping
    [203] => wo_material_dumping_type
    [204] => wo_material_list
    [205] => wo_material_list_type
    [206] => wo_material_list_item
    [207] => wo_material_list_item_type
    [208] => wo_notes
    [209] => wo_notes_type
    [210] => wo_rental_equipment
    [211] => wo_rental_equipment_type
    [212] => wo_spraying_conditions
    [213] => wo_spraying_conditions_type
    [214] => wo_status_updates
    [215] => wo_status_updates_type
    [216] => wo_tasks_list
    [217] => wo_tasks_list_type
    [218] => wo_time_clock
    [219] => wo_time_clock_type
    [220] => zipcodes
    [221] => zipcodes_type