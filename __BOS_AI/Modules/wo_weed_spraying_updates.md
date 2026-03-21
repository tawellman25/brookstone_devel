# BOS Module — wo_weed_spraying (March 2026 Updates)

## 0-Gallon Inspection Visit Guard (Added March 2026)

### Background

Crew members sometimes drive to a property, inspect it, and determine
no spraying is needed. Previously there was no way to log this visit
without creating a billing record. Requiring 0-gallon WOs gets crews
in the habit of logging every stop.

### Guard in hook_entity_presave

Location: after $gallonsUsed is fetched, before any fee calculations.

```php
if ($gallonsUsed == 0) {
  $entity->set('field_wo_total', 0);
  return;
}
```

Behavior:
- 0 gallons → $0 billed, presave returns immediately
- All fee calculations (minimum spray fee $85, trip fee $35,
  ATV charge, labor) are skipped entirely
- Any gallons including 0.25 → normal billing applies (minimum fee fires)

### Write-back Still Fires

The 0-gallon guard returns early from presave, but write-back to
property_spraying_info:weed_spraying still fires via the queue:
- hook_entity_insert/update → queue → _wo_weed_spraying_handle_work_order()
- Sets field_last_applied_date, field_last_applied_by,
  field_last_amount_applied = 0
- Status indicator on weed spray route view resets to OK/green

### Field Supports Decimal Entry

field_total_gallons_applied is decimal(10,2).
Crews can enter 0.25 gallons — this triggers the minimum spray fee.
0 = inspection only, 0.25+ = service performed.

### Multi-Chemical Entry (Added March 2026)

field_chemical on wo_chemicals_used:weed_spraying set to unlimited
cardinality. Crew can select multiple chemicals in one form submission.

Custom submit handler (_wo_weed_spraying_chemicals_multi_submit):
- Intercepts wo_chemicals_used_weed_spraying_form on new entities
- Creates one wo_chemicals_used:weed_spraying entity per selected chemical
- All created entities get the same field values (gallons, work order, etc.)
- Each entity has exactly one field_chemical reference
- Redirect to parent work order after save
- Success message: "X chemical records created"

State reporting requirement: each chemical must be a separate record.
