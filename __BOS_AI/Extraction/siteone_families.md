# SiteOne Family Pack-Rule Library

This document is the human-curated rule book for the `pack_family` taxonomy in BOS. Each entry documents the Each / Mid / Case pack rule a supplier uses for a given SKU family, the membership pattern (which SKUs belong), and the evidence trail (PDP samples, price-identity reasoning).

**Schema — implemented in Phase 3.11.** BOS has dedicated fields for the full Each / Mid / Case tier structure on both `material` and `supplier_price_ingest_row`:

- `field_pack_qty_mid_label` (list_string: Bag / Package / Box / Carton / Case)
- `field_pack_qty_mid` (integer)
- `field_pack_qty_case` (integer)
- `field_pack_family` (entity_reference → `taxonomy_term:pack_family`)
- `field_pack_data_source` (list_string: confirmed / inferred / inferred_low_confidence / listing_only)

The legacy `field_pack_quantity` is preserved unchanged for backward compatibility; the new fields are purely additive.

The `pack_family` taxonomy term carries the **canonical** rule (its own `field_pack_qty_mid_label` / `_mid` / `_case`). Individual materials may override via their own fields. The parser auto-creates pack_family terms when the scrape mentions one not yet in the taxonomy, so no scrape data is silently dropped.

**Trust-aware write rule (PriceSyncService::writePackTierToMaterial):**

- `data_source = "confirmed"` (≥2 PDPs agree): **overwrites** material's existing pack fields.
- Any other source: **only fills** empty fields on the material; does not clobber higher-trust data with lower-trust.
- `pack_family` + `pack_data_source` are metadata (not the rule itself), so they always tag-set with the most recent scrape's attribution.

Confidence levels:
- `confirmed`: At least 2 PDPs in the family confirm identical pack rule
- `inferred`: 1 PDP sampled, family rule applied to siblings
- `inferred_low_confidence`: No PDP sampled, family rule guessed from price/stock patterns; flag for Phase 2 discovery queue review

## Confirmed families (≥2 PDPs OR clear internal price-identity)

| Family | Membership rule | Each | Mid | Case | Sampled PDPs |
|---|---|---|---|---|---|
| Rain Bird Spiral Barb Fitting | `SBE*`, `SBCPLG`, `SBTEE`, `SWGF*` | 1 | Bag(50) | 250 | SBE050, SBE075, SBCPLG, SBTEE, SWGF050 |
| Rain Bird VAN | `*VAN` nozzle (4VAN..18VAN) | 1 | Bag(25) | 250 | 12VAN |
| Rain Bird R-Series | `R[size][shape]` nozzles | 1 | Bag(25) | 500 | R12H |
| Rain Bird R-VAN | `R-VAN*` rotary nozzles | 1 | Package(10) | 50 | R-VAN18 |
| Rain Bird HE-VAN | `HEVAN*` high-efficiency | 1 | Package(25) | 250 | HEVAN15 |
| Rain Bird U-Series | `RU*` U-series nozzles | 1 | — | 100 | RU15H |
| Hunter MP Rotator | `MP*`, `MPR-*` (MP1000/2000/3000/3500/800SR, MP Strip, MPCorner) | 1 | Package(10) | 200 | MP200090, MP800SR90, MP2000HT90 |
| Hunter PRO Adjustable Arc | `[size]A-NLA`, `[size]A`, `[size]AHE` (4A, 6A, 8A, 10A, 12A, 15A, 17A and HE variants) | 1 | Package(25) | 250 | 10A-NLA |
| Hunter I-40 | `I40*` (I4004SS, I4006SS, I4006SSHS, etc.) | 1 | — | 12 | I4006SS |
| Hunter Golf/Commercial Rotor | `G[7-9][0-9][0-9]*`, `GT*` (G880, G885, G990, G995, GT800, GT880, GT885) | 1 | — | 4 | G880E48P8S |
| Hunter TTS-800 | `GT800*` riserless body | 1 | — | 4 | GT800EP8 |
| Toro 570 MPR Plus | `T*HPC`, `T*QPC`, MPR Plus nozzles | 1 | Package(25) | 1000 | T15QPC |
| Toro 570Z | `570Z-*` spray bodies (4LP, 6P, 12P, etc.) | 1 | — | 50 | 570Z-4LP-PR |
| Toro T5 | `T5*` RapidSet rotors | 1 | — | 20 | T5PCK3.0-RS |
| K-Rain Super Pro | K-Rain rotor `10003-HP-CV` family | 1 | — | 12 | 10003-HP-CV |
| Underhill Impact | `SI*` impact sprinklers | 1 | — | 20 | SI100P |
| I-Pro (Irritrol) | `I-PRO*` spray bodies | 1 | — | 25 | I-PRO1200-SI-PR-CV |
| **Rotor-Case-20** (cross-brand) | Industry-standard rotor case-of-20 (PGP, I-20, 5004, T5, K-Rain ProPlus) | 1 | — | 20 | Multiple, cross-brand |
| **Spray-Body-Case-20** (cross-brand) | Common spray-body case-of-20 (Hunter Pro-Spray, PS Ultra, etc.) | 1 | — | 20 | Multiple |

### Evidence highlights

**Rain Bird Spiral Barb** is the canonical "confirmed" case: per-each price is identical across Each / Bag / Case tiers — the volume discount is baked into the higher-tier totals, not the per-unit price. That internal price-identity is what lets us upgrade from "inferred" to "confirmed": two PDPs at two different price points each show the same internal consistency, which would be a coincidence for unrelated items but is structural for a true family. SBE050 (p/90227): $0.29/ea Each, $14.40/Bag of 50 ($0.288/ea), $72.00/Case of 250 ($0.288/ea). SBE075 (p/90788): $0.32/ea Each (Bag/Case totals follow the same identity pattern). Sampled 2026-05-30.

**Cross-brand patterns (`Rotor-Case-20`, `Spray-Body-Case-20`)** are industry-standard packaging conventions for irrigation rotors and spray bodies. Not brand-specific — they appear across Hunter, Rain Bird, Toro, and K-Rain. The scrape uses them as fallback family names for SKUs that match the pattern but don't have a more specific family attribution yet.

## Inferred families (1 PDP sampled)

These are seeded with rules derived from a single PDP or strong pattern consistency across siblings. They're tagged `inferred` in the data_source; the matcher trusts them for "fill empty" writes but won't overwrite higher-trust values.

| Family | Membership rule | Each | Mid | Case | Notes |
|---|---|---|---|---|---|
| Hunter PRO Fixed Nozzle | `H[size][shape]`, `H[size]TQ`, etc. | 1 | Package(25) | 250 | H10H, H10F, H12H, H12Q all consistent across many SKUs |
| Hunter Bubbler | `PCN*`, `PCB*`, `MSBN*` | 1 | Package(25) | 250 | PCN20, PCB20, MSBN20F consistent |
| Hunter MPR Nozzle | `MPR-*` (MPR-25, -30, -35) | 1 | Bag(25) | 250 | Used on PGP/I-20 rotors |

## Inferred low-confidence (no PDP sample)

These are speculative — seeded with a plausible rule based on family-name analogy or sibling patterns but no PDP confirmation. The matcher only fills empty material fields with these; office should review when seen.

| Family | Membership rule | Each | Mid (guess) | Case (guess) | Notes |
|---|---|---|---|---|---|
| Rain Bird SA Swing Joint | `RB-SA*`, `SA1*` | 1 | Bag(50)? | 250? | Family-name analogy to Spiral Barb; no PDP confirmation |
| Hunter Stream Spray | `S[size]A-NLA` | 1 | Package(25)? | 250? | Pattern only |
| Toro Nozzle | `O-*-H`, `O-*-Q`, etc. (Toro precision nozzles) | 1 | Package(25)? | 1000? | Inferred from 570 MPR Plus sibling pattern |
| K-Rain (generic) | `RN*`, `RPS*`, `FN-*` (K-Rain rotors and nozzles) | 1 | — | 12? | Pattern-based, no PDP confirmation |
| Hunter ST Commercial | `ST-*` | 1 | — | 1? | Listing-only data; ST-series are large single units |
