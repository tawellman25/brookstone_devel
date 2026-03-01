# BOS Module Exceptions

This file documents why Tier 2 or Tier 3 modules
are currently enabled.

---

## rules
Tier: 3  
Reason:
- Legacy automation tied to historical workflows
- Gradual migration to custom code underway

Exit Plan:
- Replace with explicit event subscribers and services
- Target removal after WO refactor

---

## page_manager
Tier: 2  
Reason:
- Legacy layouts for non-operational pages

Exit Plan:
- Migrate to Layout Builder where possible
- Freeze new usage immediately

---

## computed_field
Tier: 2  
Reason:
- Used for derived values during imports

Exit Plan:
- Move logic into services where feasible
