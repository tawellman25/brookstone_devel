# BOS Entity — contacts

Entity Type ID: `contacts`
Storage: ECK

## Purpose
- Non-user people attached to customer profiles and properties.
- Contacts are not system users — they are people associated with a client or teammate record.

## Bundles
- `contact` — standard client/vendor contact
- `emergency_contacts` — emergency contacts for teammates

## Required Relationships
- `contact`: no hard required parent reference — attached via `field_contacts` on profile/property
- `emergency_contacts`: `field_teammate` → `user`
- Both: `field_phone_number` → `phone_number`, `field_address` → `address`

## Key Fields

### contact bundle
- `field_first_name`, `field_last_name` — name components
- `field_email` — primary email
- `field_phone_number` → `phone_number` (multi-value)
- `field_address` → `address`
- `field_contact_status` — list: active/inactive
- `field_job_title`, `field_gender`, `field_birthday`, `field_spouse_s_name`
- `field_contact_notes` — long text notes

### emergency_contacts bundle
- `field_first_name`, `field_last_name`
- `field_relationship` — relationship to teammate
- `field_phone_number` → `phone_number`
- `field_address` → `address`
- `field_primary` — boolean: primary emergency contact
- `field_note` — long text notes
- `field_teammate` → `user`

## Invariants
- Contacts are not users. Do not create a `user` for a contact.
- Managed by `bos_contact_attach` module — handles attachment to profiles/properties and cleanup on contact delete.
- Do not delete contacts referenced by active properties or profiles.

## Deletion / Archival
- `field_contact_status` (inactive) preferred over deletion for `contact` bundle.
- Emergency contacts may be deleted when a teammate is deactivated, after confirming no historical references.

---

# BOS Entity — address

Entity Type ID: `address`
Storage: ECK

## Purpose
- Structured address records for contacts, profiles, suppliers, and teammates.
- Four bundles serve different relationship contexts.

## Bundles
- `contact_address` — address for a `contact` entity
- `profile_mailing_addresses` — mailing address for a client profile
- `supplier` — address for a supplier
- `teammate_address` — address for a teammate

## Required Relationships
- `contact_address`: referenced via `contacts.field_address`
- `profile_mailing_addresses`: `field_user` → `user`
- `supplier`: referenced via `supplier` entity
- `teammate_address`: `field_user` → `user`

## Key Fields (all bundles)
- `field_address` — street address
- `field_city`, `field_state`, `field_zipcode`
- `field_additional_address` — apt/suite/unit

### profile_mailing_addresses additional
- `field_address_label` — label for this address
- `field_address_to`, `field_address_to_additional` — mailing name fields
- `field_address_type` — type (billing, shipping, etc.)
- `field_default_billing`, `field_default_shipping` — boolean flags
- `field_primary_address` — boolean: primary address
- `field_country` — country
- `field_address_notes` — notes
- `field_old` — boolean: legacy/old address flag

### teammate_address additional
- `field_primary_address` — boolean: primary

## Invariants
- `field_profile` on `profile_mailing_addresses` is legacy (migration artifact) — use `field_user`.

---

# BOS Entity — phone_number

Entity Type ID: `phone_number`
Storage: ECK

## Purpose
- Phone number records for contacts, profiles, and suppliers.

## Bundles
- `contacts` — phone for a `contacts` entity
- `profile_phone_numbers` — phone for a client profile
- `suppliers` — phone for a supplier

## Required Relationships
- Referenced via entity reference from `contacts.field_phone_number`, profile, or `supplier`

## Key Fields (all bundles)
- `field_phone_number` — telephone field
- `field_phone_type` — list: mobile, office, home, fax, etc.

### profile_phone_numbers additional
- `field_primary_phone` — boolean: primary contact number
- `field_user` → `user` — client this belongs to
- `field_short_internal_description` — internal memo/description
- `field_profile_attached_to` → `profile` (legacy — migration artifact, use `field_user`)

## Invariants
- `field_profile_attached_to` on `profile_phone_numbers` is a legacy field — do not use for new records.

---

# BOS Entity — classification

Entity Type ID: `classification`
Storage: ECK

## Purpose
- Reference lookup records for chemical classification types.
- Used to classify chemicals by absorption type and chemical category.

## Bundles
- `absorption` — absorption type classifications (e.g., systemic, contact)
- `chemical_types` — chemical category classifications

## Key Fields
- `title` (base) — classification name
- `field_description` — long text description

## Required Relationships
- None — pure reference/lookup entity. Referenced by `chemical` entities.

## Invariants
- Reference data. Do not delete entries that are referenced by active chemical records.

## Deletion / Archival
- Deactivate via status if no longer needed; do not delete if referenced.

---

# BOS Entity — client_type

Entity Type ID: `client_type`
Storage: ECK

## Purpose
- Reference lookup for client type classifications (e.g., residential, commercial, HOA).

## Bundles
`client_type` (single bundle)

## Key Fields
- `title` (base) — client type name

## Required Relationships
- None — pure reference/lookup. Referenced by user/profile records.

## Invariants
- Reference data. Do not delete entries in use.

---

# BOS Entity — client_app

Entity Type ID: `client_app`
Storage: ECK

## Purpose
- Records external client check-in apps that some properties use to verify service completion.
- Referenced by `property_snow_removal_info.field_app`.

## Bundles
`app` (single bundle)

## Key Fields
- `title` — app name
- `status` (base) — boolean: active
- `field_website` — app website link
- `field_apple_app_store_link`, `field_google_app_store_link` — store links
- `field_login_name`, `field_password`, `field_our_id_pin` — Brookstone's login credentials for this app
- `field_directions` — how to use the app
- `field_logo` — app logo

## Required Relationships
- Referenced by `property_snow_removal_info.field_app`

## Invariants
- `field_password` stored in plain text — treat as sensitive. Access should be restricted to admin/office roles.
- Use `status` to deactivate apps no longer in use rather than deleting.

## Deletion / Archival
- Set `status = false` (inactive) rather than deleting.

---

# BOS Entity — crew_types

Entity Type ID: `crew_types`
Storage: ECK

## Purpose
- Defines the crew types that exist in the company. Each crew type links to a department and has size, equipment, and compatibility rules.

## Bundles
`crew_types` (single bundle)

## Required Relationships
- `field_department` → `department`
- `field_crew_leader` → `user`
- `field_required_equipment` → `equipment` (multi-value)
- `field_required_equipment_types` → `taxonomy_term`
- `field_skills_certifications` → `taxonomy_term`
- `field_cross_crew_compatibility` → `crew_types` (self-referential)

## Key Fields
- `title` — crew name
- `field_crew_description` — long text description
- `field_crew_size_minimum`, `field_crew_size_maximum` — size bounds
- `field_primary_responsibilities` — primary responsibilities
- `field_seasonal_availability` — list_integer: which seasons this crew operates
- `field_cross_crew_compatibility` → `crew_types` — which other crews this crew can combine with

## Invariants
- Used by `wo_complete_info` bundle selection logic — crew type determines which `wo_complete_info` bundle is used.
- Do not delete crew types referenced by active WOs or employees.

---

# BOS Entity — department

Entity Type ID: `department`
Storage: ECK

## Purpose
- Organizational departments. Each department has associated crews, a leader, and reporting structure.

## Bundles
`details` (single bundle)

## Required Relationships
- `field_department_leader` → `positions`
- `field_reporting_structure` → `positions`
- `field_associated_crews` → `crew_types` (multi-value)

## Key Fields
- `title` — department title
- `field_department_name` — department name
- `field_department_summary` — long text summary
- `field_key_functions` — long text: key functions
- `field_compliance_requirements` — long text: compliance requirements

## Invariants
- Referenced by `crew_types.field_department`.
- Do not delete departments with active crew type references.

---

# BOS Entity — employment

Entity Type ID: `employment`
Storage: ECK

## Purpose
- HR notes and records attached to a teammate (user). Append-only in practice.

## Bundles
`notes` (single bundle)

## Required Relationships
- `field_employee` → `user`
- `uid` (base) → `user` (supervisor who entered the note)

## Key Fields
- `title` — topic/subject of the note
- `field_note` — long text: note content
- `field_evaluation` — list: evaluation type/rating
- `field_files` — attached files
- `field_pictures` — attached images

## Invariants
- Sensitive HR data — access must be restricted to admin/supervisor roles.
- Append-only in practice — do not edit notes after entry.
- Do not delete employment notes.

## Deletion / Archival
- Do not delete. Permanent HR record.

---

# BOS Entity — manufacturer

Entity Type ID: `manufacturer`
Storage: ECK

## Purpose
- Reference records for equipment and material manufacturers. Used on pump and equipment records.

## Bundles
`manufacturer` (single bundle)

## Required Relationships
- None — pure reference entity. Referenced by `property_sprinkler_pumps.field_pump_manufactured` and similar.

## Key Fields
- `field_name` — manufacturer name
- `field_website` — website link
- `field_email` — email
- `field_main_telephone`, `field_toll_free_telephone`, `field_fax_line` — phone contacts
- `field_mailing_address`, `field_shipping_address` — address fields (Drupal `address` field type, not BOS `address` ECK entity)
- `field_description` — long text
- `field_logo`, `field_banner_image` — branding images
- `field_type` — manufacturer type (list)

## Invariants
- Do not delete manufacturers referenced by active equipment or material records.

---

# BOS Entity — positions

Entity Type ID: `positions`
Storage: ECK

## Purpose
- Job position/role definitions. Used for org structure and SOP responsibility assignments.

## Bundles
`role` (single bundle)

## Required Relationships
- `field_associated_crew` → `crew_types` (optional)
- `field_reporting_structure` → `positions` (self-referential — who this position reports to)
- `field_skills_certifications` → `taxonomy_term`

## Key Fields
- `title` — position title
- `status` (base) — boolean: active/published
- `field_job_summary` — long text
- `field_key_responsibilities` — long text
- `field_qualifications` — long text
- `field_physical_requirements` — long text
- `field_work_environment` — string
- `field_flsa_status` — FLSA classification (list)

## Invariants
- Referenced by `department.field_department_leader`, `department.field_reporting_structure`, `sop:training.field_required_positions`.
- Use `status = false` to deactivate positions no longer in use.

## Deletion / Archival
- Set `status = false` (inactive) rather than deleting.
