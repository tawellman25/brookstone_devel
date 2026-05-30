# SiteOne Family Pack-Rule Library

This document captures pack-rule inferences for SiteOne supplier item families, derived from PDP sampling during brand-filtered category sweeps. Used by the Phase 2 ingest matcher to populate per-row pack-quantity facts for items inferred from family membership rather than individually visited.

**Schema note — field mapping is TBD.** BOS currently has a single `field_pack_quantity` integer on `material` and `supplier_price_ingest_row`. The library here captures the fuller Each / Mid / Case tier structure observed in supplier catalogs (because suppliers price the same SKU at three tiers — bag, case, and per-each — and that's data we don't want to throw away during extraction). Whether the matcher should pick one tier to write into `field_pack_quantity` or BOS should extend its schema to capture all three is a Phase 2 decision pending scrape-data review.

Confidence levels:
- `confirmed`: At least 2 PDPs in the family confirm identical pack rule
- `inferred`: 1 PDP sampled, family rule applied to siblings
- `inferred_low_confidence`: No PDP sampled, family rule guessed from price/stock patterns; flag for Phase 2 discovery queue review

## Confirmed families

| Family | Membership rule | Each | Mid | Case | Sampled PDPs |
|---|---|---|---|---|---|
| Rain Bird Spiral Barb (drip fittings) | SKUs starting `SBE`, plus `SBCPLG`, `SBTEE`, `SWGF*` | 1 | Bag(50) | 250 | SBE050 (p/90227), SBE075 (p/90788) |

### Rain Bird Spiral Barb — evidence

Per-each price is identical across Each / Bag / Case tiers — the volume
discount is baked into the higher-tier totals, not the per-unit price.
That internal price-identity is what lets us upgrade this family from
"inferred" to "confirmed": two PDPs at two different price points each
show the same internal consistency, which would be a coincidence for
unrelated items but is structural for a true family.

- **SBE050** (p/90227): $0.29/ea Each, $14.40/Bag of 50 ($0.288/ea),
  $72.00/Case of 250 ($0.288/ea).
- **SBE075** (p/90788): $0.32/ea Each (Bag/Case totals follow the same
  identity pattern).

Sampled 2026-05-30.

## Inferred families (1 PDP sampled)

(populate as needed from prior sweep work)

## Inferred low-confidence (no PDP sample)

| Family | Membership rule | Each | Mid (guess) | Case (guess) | Notes |
|---|---|---|---|---|---|
| Rain Bird SA Swing Joint | SKUs `RB-SA*`, `SA1*` | 1 | Bag(50)? | 250? | Same family naming as spiral barb but no PDP confirmation; flag in Phase 2 discovery |
