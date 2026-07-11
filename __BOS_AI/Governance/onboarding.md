# BOS Onboarding — Orientation for a New Developer

Read this on day one. It's the map: what BOS is, who it serves, how it's divided,
and what's safe to touch first. After this, read `README.md`,
`Entities/01_entities_policy.md`, and `Governance/working_with_claude.md`.

---

## 1. What BOS is

**BOS (Brookstone Operating System)** is the internal operations platform for
Brookstone Outdoors (a landscaping / irrigation / snow company). It centralizes
operational, client, property, and work-order data. It is **not** an ERP in
user-facing language. Built on **Drupal 10** (11-compatible), almost entirely on
**ECK** (Entity Construction Kit) — no nodes for operational data.

The authoritative documentation lives in `__BOS_AI/`. **Code conforms to those
docs, not the reverse.** Read before implementing anything non-trivial.

---

## 2. The one idea everything hangs on: Intent vs. Execution

If you understand only one thing, understand this. Three ECK entities carry the
whole business:

```
Properties (the WHERE)
   └── Contracts (the INTENT — what we agreed to do)
   └── Work Orders (the EXECUTION — what actually happened)
         ├── time clock entries (labor)
         ├── materials / chemicals used
         ├── completion sign-off
         └── billing totals
```

- **Never store execution data on a Contract; never store intent on a Work Order.**
- The **Services taxonomy** is the glue: a service term maps to exactly one
  Work Order bundle (`field_service_bundle`). **Invariant:**
  `work_order.bundle == work_order.field_service.term.field_service_bundle`.

Everything else is a supporting cast around this spine. If you can trace one
property → its contract → a work order → clock-in → materials → sign-off →
billing total, the system clicks.

---

## 3. Who uses BOS — the four audiences

BOS serves **four audiences** through **two themes**. Every feature you build is
for one (or more) of these. Knowing the audience tells you the theme, the device,
and the tone.

| Audience | Role(s) | Theme | Primary surfaces | Device | What they do |
|---|---|---|---|---|---|
| **Public** | `anonymous` | (login gate) | `/user/login`, minimal public/marketing info | any | Almost nothing — BOS is gated. Front page **is** the login. |
| **Client** | `client` | `brookstone_olivero` (Olivero) | Read-only customer portal — their properties, contracts, estimates, invoices | desktop/mobile | View their own records; approve estimates/contracts. **Read-only** (partially built). |
| **Teammates (crew)** | `teammates` (+ `supervisor`) | `brookstone_olivero` | `/teammates/*` — My Schedule, property list/map, WO execution; their `/user/{uid}` profile ("Time on Jobs") | **phone-first** | Execute work: clock in/out (green button), tasks, materials, chemicals, mark complete. |
| **Office / Admin** | `administration`, `site_assistant`, `site_admin`, `administrator` | `brookstone_admin` (Claro) | `/admin/*` — properties, contracts, scheduling, billing, estimates, materials, equipment, reports | desktop | Run operations: create/assign WOs, manage contracts, bill, estimate, purchase. |

**Two cross-cutting notes:**
- **`supervisor` is the hybrid** — sits between crew and office. Gets the **admin
  theme**, but works crew-facing surfaces (Dispatch board, assign/status WOs) and
  is the audience for oversight views (e.g. the GPS distance on the per-teammate
  hours page).
- **Theme is forced by role, not route.** The admin theme (`brookstone_admin`)
  is forced for `administrator / site_admin / administration / site_assistant /
  supervisor`; everyone else gets the default (`brookstone_olivero`). This is why
  the same page can look different for crew vs. office, and why crew-facing UI
  (property cards, My Schedule) lives in `brookstone_olivero` and must be
  **mobile-first**.

Role hierarchy (each inherits the prior):
`anonymous → authenticated → user → client → teammates → supervisor →
administration → site_assistant → site_admin → administrator`.

---

## 4. The functional domains

BOS divides into **~7 domains**. For each: what it owns, its key
modules/entities, and — most important for delegation — its **hand-off risk**
(how much damage a bug does).

| Domain | Owns | Key modules / entities | Hand-off risk |
|---|---|---|---|
| **1. Properties & Clients** | The who/where | `properties`, `contacts`, `address`, `customer_profile`, `ownership_record` | 🟢 Low — mostly self-contained |
| **2. Contracts** | The *intent* / agreements | `contracts`, `contract_sections`, `contract_residential` (+ audit) | 🔴 High — status lifecycle, one-per-property-per-year |
| **3. Work Orders** | The *execution* engine | `work_order` + 31 `wo_*` bundle modules + child entities + `property_*` detail entities | 🔴 High — bundle-specific billing math |
| **4. Estimating** | The sales → work pipeline | `estimate`, `estimate_request`, `estimate_items`, WO/contract conversion | 🟠 Med-high — the "revenue epic" |
| **5. Scheduling & Time/Labor** | Calendar + clocking + hours | `bos_scheduling`, `admin_calendar`, `business_calendar`, `wo_clock`, `wo_time_clock`, `wo_total_time`, `bos_teammate_operations` | 🟠 Medium — guards affect payroll perception |
| **6. Billing & Accounting** | Money out | WO totals, `wo_sign_off`, invoicing, QuickBooks export (planned) | 🔴 High — real dollars, hard to reverse |
| **7. Supporting subsystems** | Self-contained pipelines | **Materials/pricing** (`material`, `material_supplier`, `supplier_price_ingest`), **Equipment/Fleet** (`equipment*`, `bos_wex_import`), **Reporting** (`bos_daily_recap`) | 🟢 Low — great starter areas |

Underneath all of them sits **Reference & Governance** — the "rules engine":
the **Services** taxonomy, rate tables in `config_pages:business_setting`,
`zipcodes` (trip fees), `sq_ft_break_points` (area pricing), user roles, and the
**SOPs**. Business logic reads from here; it is not a feature domain but everyone
depends on it.

---

## 5. What's safe to hand off vs. earn-trust-first

The domains split into two buckets — this is how you decide what to give a new
person:

**🟢 Safe to hand off (isolated, low blast radius) — start here:**
- Equipment & Fleet (WEX fuel import, inspections)
- Materials & Supplier Pricing (its own parse → match → commit pipeline)
- Reporting / dashboards (daily recap, teammate ops — read-mostly)
- Scheduling / crew-UI polish (cards, mobile fixes)

These are their own pipelines with clear inputs/outputs; they don't touch money
or the contract lifecycle.

**🔴 Earn trust first (coupled to the spine or to money):**
- Work Order billing (`wo_*` modules) — a bug bills a customer wrong
- Contracts lifecycle (status transitions, one-per-year enforcement)
- Estimating → WO/contract conversion
- Time/labor guards (over-cap, sign-off) — affect payroll perception + billing
- **Anything that writes to invoiced/billed data** — there's a deliberate guard
  blocking it for a reason.

---

## 6. House rules you must internalize

These aren't obvious from the code and are the usual way people get burned:

- **`__BOS_AI/` is authoritative** — read the relevant `.md` before building; do
  not invent entities/bundles/rules not defined there.
- **The `wo_*` pattern is deliberate** — one module per Work Order bundle. Do
  **not** consolidate them.
- **`property_*` detail entities are read-write** — `wo_*` modules read from them
  (to pre-fill a WO) and write back to them (service history) on completion.
- **Config is intentionally drifted** (~340 configs differ from `config/sync`).
  **Never** a full `drush cim` or blind `drush cex` — surgical **partial** only.
- **Deploy model:** rsync code + `composer install` + `drush cr`. The **DB is
  never touched** by a deploy. `contrib/` is composer-managed (not committed).
  ECK / config_pages field configs **silently skip** on `cim` — create them via
  entity-API **setup scripts** in `web/scripts/`.
- **Rates live in `config_pages:business_setting`**, not in code.
- **`ROADMAP.md` is the status-of-record.** Reconcile it in the same session as
  any shipping change.
- Process discipline (pause-and-verify, targeted commits, end-to-end
  verification): `Governance/working_with_claude.md`. BOS/Drupal traps:
  `Governance/drupal_bos_gotchas.md`. Reusable patterns:
  `Governance/architectural_patterns.md` and `Governance/ui_patterns.md`.

---

## 7. Your first week — a concrete path

1. **Read** this doc, then `README.md`, `Entities/01_entities_policy.md`,
   `Governance/working_with_claude.md`, and skim `Governance/drupal_bos_gotchas.md`.
2. **Trace the spine end-to-end** in the running app: open a property → its
   contract → a work order → clock in/out → add a material → sign off → see the
   billing total. Do it as an office user *and* look at the crew view.
3. **First task in a 🟢 domain** — a reporting tweak or an equipment/fleet view.
   It teaches the real patterns (ECK, status-card UI, the deploy flow) without
   risk.
4. **Learn the UI conventions** — the status-card pattern (`ui_patterns.md`), the
   US date-formatting rule, and the two-theme split.
5. **Only then** go near a `wo_*` module, a contract action, or anything billing.

---

## 8. Where to read next

- **System overview:** `README.md`, `Entities/01_entities_policy.md`,
  `Entities/03_bos_ui_flow_map.md`
- **The spine:** `Modules/wo_bundle_modules.md`,
  `Entities/property_detail_entities.md`, contract docs under `Modules/`
- **Roadmap / what's unfinished:** `ROADMAP.md`
- **Traps & patterns:** `Governance/drupal_bos_gotchas.md`,
  `Governance/architectural_patterns.md`, `Governance/ui_patterns.md`
- **Working norms:** `Governance/working_with_claude.md`
