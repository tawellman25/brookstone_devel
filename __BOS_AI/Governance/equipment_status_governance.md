# BOS Governance — Equipment Status Lifecycle

Vocabulary: `equipment_status`
Used by: `equipment.field_status` (all 8 equipment bundles)

---

## Purpose

Defines the lifecycle states for all BOS equipment entities. Equipment status
drives operational visibility, maintenance scheduling, and asset management
decisions. Status must accurately reflect the current physical and operational
state of the equipment at all times.

---

## Status Definitions

### Active (TID 1301)
Equipment is integrated into daily operations.
- In use and fully operational
- Available and ready for deployment at any time
- On-site and accounted for
- No immediate maintenance or issues pending

Use this for: equipment currently in the field or at the shop, ready to work.

### Deployed (TID 1309)
Equipment is in active use at a specific location, often away from the main facility.
- Installed or actively used at a client site, project location, or remote facility
- Assigned to a particular task, project, or individual
- May require location tracking
- Still operational and contributing to work

Use this for: equipment sent to a job site or assigned long-term to a crew/project.

### Idle (TID 1311)
Equipment is not currently in use but remains in working condition.
- Operational and ready for short-notice deployment
- Not contributing to current workflow
- May be idle due to seasonal demand, waiting for a project, or serving as backup

Use this for: seasonal equipment between seasons, backup units, underutilized assets.

### In Storage (TID 1308)
Equipment is stored and not in active use.
- Maintained in a condition where it could be used again
- Stored in a designated area (on-site or off-site)
- Tracked in inventory as an asset
- Held for future use, seasonal demand, or emergency replacement

Use this for: winterized equipment, reserve inventory, equipment between assignments.

### On Loan/Loaned Out (TID 1307)
Equipment has been temporarily provided to another party.
- Temporarily provided to an individual, department, or external entity
- Ownership remains with Brookstone
- Return is expected in the same condition (minus normal wear)
- Must be tracked for location, user, and expected return date

Use this for: equipment lent to another crew, department, or external party.

### Being Serviced (TID 1303)
Equipment is undergoing maintenance, repair, or service work.
- Currently with technicians or service personnel
- Not available for regular use during service
- May be scheduled maintenance or unplanned repair
- Service activities must be documented

Use this for: equipment at the shop for maintenance, at a dealer for repair,
or undergoing scheduled service.

### Needing Repairs (TID 1302)
Equipment requires maintenance or repair before it can return to service.
- Not functioning as intended due to mechanical or technical issues
- May be awaiting parts or a technician
- Currently inoperable for its designed function

Use this for: broken equipment that has been identified but not yet taken in
for service. Transitions to "Being Serviced" when work begins.

### Unrepairable (TID 1304)
Equipment cannot be economically or technically repaired.
- Repair is not feasible or cost exceeds replacement value
- Required parts may be unavailable
- May pose safety risks if repair is attempted

Use this for: end-of-life equipment. Leads to salvage, disposal, or sale.

### Salvaged for Parts (TID 1305)
Equipment has been dismantled and usable components harvested.
- More valuable as parts than as a whole unit
- Parts removed for reuse, resale, or recycling
- Remaining frame/shell may be disposed of

Use this for: decommissioned equipment being stripped for useful components.

### Lost/Stolen (TID 1310)
Equipment is no longer in Brookstone's possession.
- Missing due to loss or theft
- Must be reported to authorities and insurance
- Triggers security review
- May be temporary if recovery is possible

Use this for: equipment that cannot be located or has been confirmed stolen.

### Sold (TID 1306)
Equipment has been sold and ownership transferred.
- Legally sold to a new owner
- Removed from company asset inventory
- Financial records updated (depreciation ceased, revenue recognized)
- No longer available for operational use

Use this for: equipment that has been sold. Final lifecycle state.

---

## Lifecycle Transitions

```
Active ←→ Deployed ←→ Idle ←→ In Storage
  ↓           ↓         ↓         ↓
  └─→ On Loan/Loaned Out ←──────┘
  ↓
  └─→ Needing Repairs → Being Serviced → Active (repaired)
                                       → Unrepairable → Salvaged for Parts
                                                      → Sold
  └─→ Lost/Stolen → (recovered) → Active
                   → (unrecovered) → write-off
```

### Common transitions
- **Active → Needing Repairs → Being Serviced → Active**: standard repair cycle
- **Active → Deployed → Active**: job site assignment and return
- **Active → Idle → In Storage**: seasonal equipment storage
- **Needing Repairs → Unrepairable → Salvaged for Parts**: end-of-life path
- **Any → Sold**: equipment sale (terminal state)
- **Any → Lost/Stolen**: unplanned loss (hopefully temporary)

---

## Rules

- Equipment status must reflect current physical reality, not planned state
- Status changes must be timely — do not leave equipment in stale states
- "Needing Repairs" must transition to "Being Serviced" or "Unrepairable" — it is not a parking state
- "Lost/Stolen" requires incident reporting before status is set
- "Sold" is a terminal state — equipment must not transition back from Sold
- "Salvaged for Parts" is a terminal state — the whole unit no longer exists

---

## Related Entities

- `equipment_status_update` (bundle: `update`) — tracks status change events
- `equipment_check_in_out` (bundle: `check_in`) — tracks physical custody changes
- `equipment_status_updates` module — propagates status update entity changes to equipment entity

---

Created: April 2026
