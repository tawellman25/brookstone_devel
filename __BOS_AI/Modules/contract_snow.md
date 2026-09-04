# contract_snow — Snow Removal Contract

**Package:** Contract
**Entity/bundle:** `contracts:snow_removal`
**PDF route:** `contract_snow.agreement_pdf` — `/contracts/snow/{contracts}/agreement`
**Template version:** `CONTRACT_SNOW_TEMPLATE_VERSION` (currently `v1.0`)
**Shipped:** P1–P3 live 2026-08 / 2026-09

## Purpose

Governs the residential **Snow Removal Service Agreement** — the intent record
for a season's snow service. Parallels `contract_residential` but is its own
module because the snow agreement has bespoke pricing (tiered per-plowing rates,
per-customer ice rates) and a printed/signed customer-facing PDF.

The snow contract is **intent**, not execution — plowing/ice work is recorded on
`work_order:snow_removal` at service time (see Intent-vs-Execution in the
architecture rules).

## Fields (snow_removal bundle)

Built via entity-API setup scripts (ECK/field configs silent-skip on cim), not
`drush cim`. Scripts under `web/scripts/`:

- **Tiered plow rates** (`setup_snow_tiered_plow_rates.php`):
  `field_plow_rate_0_2`, `field_plow_rate_2_4`, `field_plow_rate_4_6`,
  `field_plow_rate_6_plus` — **rate per complete plowing** at each snow-depth
  band. `field_per_push_rate` (pre-existing) also retained.
- **Contract data** (`setup_snow_contract_fields.php`):
  `field_snow_contract_number` (SNOW-{year}-{id}, auto),
  `field_snow_service_method`, `field_snow_trigger` (→ `snow_trigger` vocab),
  `field_snow_ice_authorized`, `field_salt_rate`, `field_mag_rate`,
  `field_shovel_rate`, `field_snow_property_instructions`,
  `field_snow_template_version`.
- **Snow trigger** (`setup_snow_trigger_vocab.php` + `setup_snow_trigger_other_field.php`):
  `snow_trigger` taxonomy vocabulary — each option gets a **description page**
  (future client link + crew training). `field_snow_trigger` is an
  entity_reference to it. `field_snow_trigger_other` (string) is shown on the
  form only when the "Other" term is selected (`#states` in
  `contract_snow_form_alter`).
- **Ice pricing** (`setup_snow_ice_max_fields.php` + `setup_snow_ice_pricing_defaults.php`):
  per-customer `field_salt_rate` / `field_mag_rate`; per-visit cap
  `field_snow_ice_max_amount` + `field_snow_ice_max_unit` (Bags/Pounds/Gallons).
  Business-Settings defaults `field_default_salt_per_lb` (seeded $0.85) +
  `field_mag_chloride_rate` prefill a new contract via
  `contract_snow_contracts_prepare_form`.
- **Status** (`setup_snow_contract_status.php`): reuses the existing
  `field_contract_status` (→ `contract_status` vocab) + a new
  **"Executed / Active"** term.
- **Signed PDF** (`setup_snow_signed_pdf_field.php`): `field_snow_signed_pdf`
  (file, PDF only) — the scanned executed agreement. Files → `public://`
  (s3fs on live) under `snow-signed-contracts/{Y}`.

## The agreement PDF (P2)

`SnowAgreementController::pdf()` renders `templates/snow-agreement.html.twig`
through `entity_print` + `dompdf` (same infra as backflow). 2 pages: pricing +
trigger + ice-control note on page 1, full Terms & Conditions + signature on
page 2.

- **QR** (endroid/qr-code v6) encodes a stable BOS identifier so a scanned copy
  routes back to the contract.
- **Customer-facing only** — internal WO fields (1/4 Plow, Helped Plow, etc.)
  are never rendered on the agreement.
- **Ice pricing is NOT dollar-itemized** on the PDF — replaced by a market-price
  note ("Ice-control material is billed by the pound (salt) or gallon (mag
  chloride) at current market price"), with the per-visit Bag/Pound max shown.
- dompdf gotchas honored: charset `<meta>` (multibyte), fixed-position footer
  page counter, Twig rendered via `renderInIsolation($build)` (a variable, not an
  inline array).

## Workflow toolbar + version-lock (P3)

`contract_snow_contracts_view()` injects `SnowContractActionsForm` at the top of
a snow contract page (`full`/`default` view mode). The toolbar shows the current
status and:

- **Preview / Print Agreement** → `contract_snow.agreement_pdf` (new tab).
- **Mark Sent** → status **"Sent - Posted"**.
- **Upload Signed** → the contract edit form (`field_snow_signed_pdf`).
- **Activate** → status **"Executed / Active"**. **Disabled until a signed PDF is
  on file.**

Status transitions resolve `contract_status` terms **by name** at runtime
(`contract_snow_status_tid()`) because term tids are per-environment content.

**Hard version-lock:** once a contract's status is Executed/Active,
`contract_snow_contracts_presave()` throws `EntityStorageException` on any edit to
a pricing/terms field (`_contract_snow_locked_fields()`). Status and the signed
PDF stay editable — to revise pricing, the office moves it off Executed/Active or
creates a **new season contract**. The Activate save itself passes because the
*saved* (pre-activation) status is not yet Executed/Active.

## Auto contract number

`contract_snow_contracts_insert()` assigns `SNOW-{year}-{id}` on insert via a
second save (the id isn't available during presave on insert — same pattern as
the AEL sentinel heal in `wo_shared`). Backfilled on live by
`backfill_snow_contract_numbers.php`.

## Deploy path

Entity-API setup scripts run on each environment (dev then live via
`brookstone-new`), module rsynced, `drush cr`. No `drush cim` (config/sync
intentionally drifted). Live drush:
`/opt/alt/php83/usr/bin/php -d memory_limit=768M vendor/drush/drush/drush.php`.

## Not built yet

- **P4** — wire the tiered plow rates into `wo_snow_removal` billing (measured
  plow depth → matching rate band). Until then the tiers are contract-side only.
- Snow-trigger term **descriptions** (Todd/Chat author at
  `/admin/structure/taxonomy/manage/snow_trigger/overview`).
- Real per-lb salt price in Business Settings (seeded $0.85 placeholder).
