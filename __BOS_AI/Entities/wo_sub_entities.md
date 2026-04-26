# BOS Entity — wo_chemicals_used

Entity Type ID: `wo_chemicals_used`
Storage: ECK

## Purpose
- Records chemicals applied during a spray work order, one entity per chemical used.
- Captures compliance data: amount added to tank, gallons applied, cost snapshot.
- Cost snapshot (`field_chemical_cost`) is written at time of use — never read live from `chemical`.

## Bundles
`aspen_twig_gall`, `cooley_spruce_gall`, `deciduous_bore`, `dormant_oil`, `fertilizers`, `fertilizers_tree_and_shrubs`, `fertilizing_chemicals`, `grub_prevention`, `pinion_pine_ips_beetle`, `pre_emergent`, `trunk_bore`, `weed_spraying`

## Required Relationships
- `field_work_order` → `work_order` (required — all bundles)
- `field_chemical` → `chemical` (required — all bundles)

## Key Fields
- `field_chemical_cost` — snapshot of unit cost at time of use; immutable after WO completion
- `field_subtotal` — computed cost total for this chemical line
- `field_total_gallons_applied` — compliance record
- `field_total_added_to_sprayer` — compliance record
- `field_per_how_many_gallons` — dilution ratio

### Bundle-specific key fields
- `fertilizers`: `field_fertilizer_lbs_applied`, `field_rate`, `field_did_sq_ft_of_lawn_change`, `field_new_sq_ft_of_lawn`
- `fertilizers_tree_and_shrubs`: `field_applied_to_trees`, `field_applied_to_shrubs`, `field_applied_to_garden`, `field_number_of_trees`, `field_number_of_shrubs`, `field_sq_ft_of_garden_beds`
- `fertilizing_chemicals`: `field_spreader_sticker_adjuvalen`, `field_spreader_cost`
- `grub_prevention`: `field_fertilizer_lbs_applied`, `field_spreader_sticker_adjuvalen`
- `weed_spraying`: `field_spreader_sticker_adjuvalen`, `field_spreader_cost`
- `pinion_pine_ips_beetle`: `field_number_of_trees`

## Invariants
- Must not read live pricing from `chemical` — cost is snapshotted at use time.
- One entity per chemical per WO (multiple chemicals = multiple entities).
- Do not delete after WO completion — compliance record.
- `field_chemical_cost` is immutable once WO is marked Complete (term 1097).

## Deletion / Archival
- Do not delete. Retain as compliance and billing history.

## Integration
- None. Compliance records are BOS-authoritative.

---

# BOS Entity — wo_complete_info

Entity Type ID: `wo_complete_info`
Storage: ECK

## Purpose
- Crew sign-off record for a work order. Triggers WO completion lifecycle via `wo_sign_off` module.
- One entity per WO. Creating this entity (with `field_work_order_completed = true`) drives WO status to Complete (1097).
- Deleting this entity reverts WO to In Progress (1092) and clears all billing totals.

## Bundles
`clean_up_crew`, `complete`, `fertilizing_crew`, `irrigation_crew`, `landscape_crew`, `lawn_mowing`, `snow_removal`, `spray_crew`

Bundle used is determined by the WO's crew type, not the service bundle.

## Required Relationships
- `field_work_order` → `work_order` (required — all bundles)
- `field_signed_off_by` → `user` (required)

## Key Fields
- `field_work_order_completed` — boolean; when true + saved, triggers `wo_sign_off` completion logic
- `field_canceled` — boolean; cancellation path (most bundles)
- `field_canceled_why` — cancellation reason
- `field_date_completed` — timestamp of completion
- `field_those_on_crew` → `user` — multi-value crew members present (most bundles)
- `field_how_many_trucks_taken` — truck count for billing (most bundles)

### Bundle differences
- `lawn_mowing`: no `field_those_on_crew`, no `field_how_many_trucks_taken`
- `snow_removal`: no `field_canceled` / `field_canceled_why`

## Invariants
- Only one `wo_complete_info` per WO.
- Deletion is controlled — reverts WO status and clears billing totals (enforced by `wo_sign_off`).
- `field_work_order_completed = true` is the trigger; saving with false does not complete the WO.

## Deletion / Archival
- Deletion is a valid operation (un-complete a WO) but must go through `wo_sign_off` logic.
- Do not hard-delete from completed/invoiced WOs.

---

# BOS Entity — wo_material_list

Entity Type ID: `wo_material_list`
Storage: ECK

## Purpose
- Container entity grouping material line items for a WO or estimate.
- One list per WO (bundle: `material_list`) or one per estimate (bundle: `estimate_list`).
- Line items are `wo_material_list_item` entities referencing this list.

## Bundles
- `material_list` — attached to a `work_order`
- `estimate_list` — attached to an `estimate`

## Required Relationships
- `material_list`: `field_work_order` → `work_order`
- `estimate_list`: `field_estimate` → `estimate`

## Key Fields
- `field_materials_for` — plain text label describing what the list covers

## Invariants
- One material list per WO; one estimate list per estimate.
- Do not delete a material list if it has line items — cascade must be handled explicitly.
- Managed by `wo_material_list_management` module.

## Deletion / Archival
- Deletion controlled by `wo_material_list_management`.
- Do not delete from completed/invoiced WOs.

---

# BOS Entity — wo_material_list_item

Entity Type ID: `wo_material_list_item`
Storage: ECK

## Purpose
- Individual line item on a material list. Records what was used, how it was acquired, cost snapshot, and subtotals.
- Supports both stocked materials (from `material` entity) and purchased materials (one-off purchases).

## Bundles
`items` (single bundle)

## Required Relationships
- `field_list_id` → `wo_material_list` (required — parent list)
- `field_parts_used` → `material` (conditional — stocked material path; visible when material_type = stocked_item)
- `field_purchased_supplier` → `supplier` (conditional — visible when `field_material_cost` is filled). Labeled "Bought From" in the UI. Captures the vendor for both purchased items and stocked items where the crew overrode the catalog price.

## Key Fields
- `field_material_type` — `list_string`: how acquired (stocked vs purchased)
- `field_material_cost` — snapshot of unit cost at time of use; immutable after WO completion. Used as the trigger to reveal Bought From and Supplier Invoice # fields.
- `field_supplier_invoice_number` — string (max 64). Invoice/receipt # from the vendor when crew records a non-catalog price. Soft-required — visible on the form (revealed when material_cost is filled), but no field-level enforcement. JS copy-down auto-fills empty invoice fields on subsequent lines from the first entry.
- `field_supplier_item_number` — string (max 255). Vendor SKU / item number for the material the crew bought. Helpful for the office when re-ordering. Soft-required (revealed alongside the invoice field). Synced to `material_suppliers.field_supplier_item_number` by PriceSyncService **only when the supplier link's existing SKU is empty** — manual catalog edits are never overwritten by WO entries. Auto-normalized at the supplier link level by `material_supplier.module` (strips common pasted prefixes like `Item #`, `SKU`, `Part #`).
- `field_quantity` — units used
- `field_subtotal` — quantity × cost
- `field_subtotal_w_markup` — subtotal with markup applied
- `field_alternate_name_description` — name/description for purchased (non-stocked) items
- `field_reciept_pic` — image of purchase receipt

## Invariants
- `field_material_cost` is a snapshot — never read live from `material` after WO completion.
- `field_subtotal` and `field_subtotal_w_markup` computed by `wo_material_item_subtotal` module.
- Stocked path: `field_parts_used` populated, `field_alternate_name_description` empty.
- Purchased path: `field_alternate_name_description` populated, `field_parts_used` empty.
- When crew enters a non-catalog price: `field_purchased_supplier` is required (enforced by the `wo_material_price_sync` module's PriceSyncService at presave). Save is blocked with the message "Bought From vendor is required when the material price is changed from catalog."

## Form Display Behavior
- `field_purchased_supplier` (Bought From) and `field_supplier_invoice_number` are hidden until the crew enters a value into `field_material_cost`. Implemented via the `conditional_fields` module with `condition: '!empty'` on `field_material_cost`. This keeps the form clean for the common "stocked item, no price change" case.

## Deletion / Archival
- Do not delete from completed/invoiced WOs.

## Issues / Notes (Observed from current schema)

- `field_supplier` is LEGACY — `entity_reference → user`. From the old system where suppliers were expected to update their own prices via user accounts. Never implemented. The current authority for vendor capture on a WO line is `field_purchased_supplier` → `supplier` ECK entity. **Marked for removal** once we confirm:
  - No active code reads from `wo_material_list_item.field_supplier`
  - No reports or invoice exports reference it
  - All historical data has been audited (the old field's data is not migrated to `field_purchased_supplier` because old data referenced users, not supplier entities — historical lookups go via `field_purchased_supplier` going forward)
  - The form display config has it under `hidden:` so users do not interact with it (already done as of April 2026)
- The label on `field_supplier_invoice_number` is `Supplier Invoice #` — the `#` is part of the label, not a Markdown header.

---

# BOS Entity — wo_material_dumping

Entity Type ID: `wo_material_dumping`
Storage: ECK

## Purpose
- Records dump loads associated with a landscaping/cleanup WO.
- Captures dump location, load size, fees, and receipt. Feeds into `field_dump_fee_total` on the WO.

## Bundles
`load` (single bundle)

## Required Relationships
- `field_work_order` → `work_order`
- `field_where_was_it_dumped` → `taxonomy_term`

## Key Fields
- `field_load_s_amount` — load size (list): bucket_or_less, few_buckets, quarter_load, half_load, full_load, hopper_full, hopper_half
- `field_dumping_how_many_full_load` — count of full loads
- `field_dump_fee` — receipt amount
- `field_dump_rate` — rate used for calculation
- `field_dump_total` — computed total
- `field_billing_adjustment` — manual adjustment to dump billing
- `field_dump_other_location` — free-text if dumped off-route
- `field_reciept_pic` — receipt photo
- `field_where_was_it_dumped` → taxonomy term for dump location

## Invariants
- Managed by `wo_dump_fees` module.
- Totals roll up to `work_order.field_dump_fee_total` on WO completion.

## Deletion / Archival
- Do not delete from completed/invoiced WOs.

---

# BOS Entity — wo_notes

Entity Type ID: `wo_notes`
Storage: ECK

## Purpose
- Structured notes attached to a work order. Replaces legacy comment-based notes.
- Append-only in practice; each note is a discrete entity with author and timestamp.

## Bundles
`note` (single bundle)

## Required Relationships
- `field_work_order` → `work_order`

## Key Fields
- `field_note_text` — long text note body
- `uid` (base) — note author
- `created` (base) — note timestamp

## Invariants
- Managed by `wo_notes` module.
- Do not edit notes after creation — append new notes instead.
- Do not delete notes from completed WOs.

## Deletion / Archival
- Notes are operational history. Do not delete.

---

# BOS Entity — wo_rental_equipment

Entity Type ID: `wo_rental_equipment`
Storage: ECK

## Purpose
- Records equipment used or rented for a work order. Tracks whether equipment was company-owned or rented, hours, rate, and cost.

## Bundles
`equipment_rental` (single bundle)

## Required Relationships
- `field_rented_for` → `work_order`
- `field_equipment_used` → `equipment` (conditional — company-owned path)
- `field_rented_from` → `user` (who rented from)

## Key Fields
- `field_equipment_rented` — boolean: was this a rental?
- `field_rental_equipment_name` — name of rented equipment (rental path)
- `field_hourly_rate` — rate charged
- `field_hours` — hours used
- `field_receipt_total_cost` — total cost from receipt
- `field_rental_returned` — boolean: returned flag

## Invariants
- Company-owned path: `field_equipment_used` populated.
- Rental path: `field_rental_equipment_name` populated, `field_equipment_used` empty.
- Totals roll up to `work_order.field_rental_total` on WO completion.

## Deletion / Archival
- Do not delete from completed/invoiced WOs.

---

# BOS Entity — wo_spraying_conditions

Entity Type ID: `wo_spraying_conditions`
Storage: ECK

## Purpose
- Compliance record of environmental conditions at time of spray application.
- Required for pesticide/herbicide application records per regulatory standards.

## Bundles
`fertilizing`, `grub_prevention`, `pre_emergent`, `tree_spraying`, `weed_spraying`

## Required Relationships
- `field_work_order` → `work_order` (all bundles)
- `field_wind_speed` → `taxonomy_term`
- `field_wind_direction` → `taxonomy_term`

## Key Fields
- `field_temperature` — integer, degrees F
- `field_wind_speed` → taxonomy term
- `field_wind_direction` → taxonomy term
- `field_soil_moisture` → taxonomy term (most bundles)
- `field_how_applied` → taxonomy term
- `field_method_of_application` → taxonomy term (most bundles)
- `field_carrier` → taxonomy term (fertilizing, pre_emergent, tree_spraying, weed_spraying)
- `field_sprayed_areas` → taxonomy term (fertilizing, pre_emergent, weed_spraying)
- `field_weed_types` → `lawn_and_garden_pests` (fertilizing, weed_spraying)
- `field_other_weed_types` — free text (fertilizing, weed_spraying)
- `field_stage_of_weed_growth` → taxonomy term (fertilizing, weed_spraying)
- `field_how_many_trees` — integer (tree_spraying only)

## Invariants
- One record per spray WO. Compliance record — do not delete.
- `field_weed_types` references `lawn_and_garden_pests` entity, not a taxonomy term.

## Deletion / Archival
- Do not delete. Regulatory compliance record.

---

# BOS Entity — wo_status_updates

Entity Type ID: `wo_status_updates`
Storage: ECK

## Purpose
- Append-only event timeline for work order status changes.
- Each status transition creates a new entity — never edited after creation.

## Bundles
`update` (single bundle)

## Required Relationships
- `field_status_of_wo` → `work_order`
- `field_status` → `taxonomy_term` (WO status vocabulary)

## Key Fields
- `field_status` → taxonomy term for the new status
- `field_status_change_note` — reason for the status change
- `uid` (base) — who made the change
- `created` (base) — when the change occurred

## Invariants
- Append-only. Never edit or delete status update records.
- Managed by `wo_status_updates` module which propagates changes back to the WO.
- Created automatically by `wo_schedule` module on scheduling entity creation.

## Deletion / Archival
- Do not delete. Permanent audit trail.

---

# BOS Entity — wo_tasks_list

Entity Type ID: `wo_tasks_list`
Storage: ECK

## Purpose
- Crew task checklist for a work order. Records what tasks were performed, time spent, and crew details.
- Bundle is matched to the WO service type.

## Bundles
`aerating`, `dethatching`, `lawn_mowing`, `snow_removal`, `special_mowing`

## Required Relationships
- `field_work_order` → `work_order` (all bundles)

## Key Fields (shared)
- `field_completed` — boolean completion flag (lawn_mowing, snow_removal)

### aerating / dethatching
- `field_measured_sq_ft` — actual sq footage measured on site
- `field_aerating_equipment_used` / `field_dethatching_equipment_used` → `equipment`
- `field_sprinkler_heads_marked` / `field_sprinkler_heads_we_marked` — sprinkler marking confirmation

### lawn_mowing / special_mowing
- `field_mowing_mowed`, `field_mowing_trimmed`, `field_mowing_edged`, `field_mowing_cleaned_debri`, `field_mowing_picked_up_trash` — task completion booleans
- `field_mowing_why_not` / `field_mowing_why_not_describe` — reason if not mowed
- `field_mowing_partial_mow` — partial completion flag
- `field_mowing_who_on_site` → `user` — crew present
- `field_mowing_assigned_to` → `user`
- Time tracking: `field_mowing_edging_minutes`, `field_mowing_debris_minutes`, `field_mowing_trash_minutes`, `field_mowing_other_minutes`
- Men counts: `field_mowing_edging_men`, `field_mowing_debris_men`, `field_mowing_trash_men`, `field_mowing_other_men`
- `field_additional_work_requested` + `field_additional_work_describe`
- `field_notes_to_office`

### snow_removal
- `field_snow_plowed`, `field_snow_shoveled`, `field_snow_applied_salt`, `field_snow_mag_chloride` — task booleans
- `field_pounds_of_salt`, `field_snow_mag_gallons` — quantities
- `field_snow_level` → taxonomy term
- `field_snow_plowed_pushes` → taxonomy term
- `field_driveways_plowed` → `property` (multi-value, which driveways were plowed)
- `field_time_shoveling` — minutes shoveling
- `field_snow_plow_photos` — photo documentation

## Invariants
- One `wo_tasks_list` per WO.
- `field_measured_sq_ft` on aerating/dethatching feeds into billing calculations via `wo_aerating` / `wo_dethatching` modules.
- `field_driveways_plowed` references `property` (bundle: `included_address`), not `properties`.

## Deletion / Archival
- Do not delete from completed WOs.

---

# BOS Entity — wo_time_clock

Entity Type ID: `wo_time_clock`
Storage: ECK

## Purpose
- Individual time punch entries for crew members on a work order.
- Multiple entries per WO (one per crew member per time block).
- Rolls up to `work_order.field_total_time` via `wo_total_time` module.

## Bundles
`entry` (single bundle)

## Required Relationships
- `field_work_order` → `work_order`
- `field_teammate` → `user`
- `uid` (base) → `user` (same as `field_teammate` in practice)

## Key Fields
- `field_start_time` — datetime
- `field_end_time` — datetime
- `field_total_time` — computed field (type: `wo_total_time`); auto-calculated from start/end
- `field_notes` — optional notes for the time entry

## Invariants
- `field_total_time` is a computed field — do not write to it directly.
- Roll-up to WO total time managed by `wo_total_time` module.
- Do not delete time entries from completed/invoiced WOs.

## Deletion / Archival
- Do not delete from completed WOs. Payroll and billing history.

---

# BOS Entity — wo_estimate_notes

Entity Type ID: `wo_estimate_notes`
Storage: ECK

## Purpose
- Notes attached to a work order that are estimate-related in context.
- Distinct from `wo_notes` (general WO notes) — scoped to estimate/pricing context.

## Bundles
`note` (single bundle)

## Required Relationships
- `field_work_order` → `work_order`

## Key Fields
- `field_estimate_note` — long text note body
- `uid` (base) — author
- `created` (base) — timestamp

## Invariants
- Append-only in practice.
- Do not delete from completed WOs.

## Deletion / Archival
- Do not delete. Operational history.
