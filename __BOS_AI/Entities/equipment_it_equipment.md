# Entity Bundle — `equipment.it_equipment` (IT / Computer Equipment)

**Entity type:** `equipment` (existing ECK entity — the same one that holds
vehicles, trailers, mowers, sprayers, etc.) · **Bundle:** `it_equipment` ·
**Added:** 2026-08-01

## Why this lives on `equipment` (design decision — settled)

IT gear (office PCs, NAS, switches, router/gateway, printers) is tracked as a new
**bundle on the existing `equipment` ECK entity**, not a separate entity type. It
therefore **reuses the existing equipment machinery for free**: `equipment_defect`,
`equipment_maintenance_event`, `equipment_inspection` (standard checklist),
status governance, and the equipment views. No alternative was evaluated — this
was a made decision.

## Field strategy

**Reuse existing `equipment` fields wherever one already fits; add net-new
fields only for genuinely IT-specific attributes.** Net-new fields are prefixed
`field_it_` so the IT set is discoverable and cannot collide with existing
equipment fields. Net-new field *storages* are created but **instanced only on
`it_equipment`**, so no other equipment bundle is affected.

### Reused fields (instances on `it_equipment` → existing shared storages)

| Field | Type | Label on this bundle | Maps from baseline |
|---|---|---|---|
| `field_equipment_number` | string | Asset ID | Asset ID (`BUS-*`) — **idempotency key** |
| `field_equipment_make` | string | Manufacturer | Manufacturer |
| `field_model` | string | Model | Model |
| `field_serial_code_number` | string | Serial Number | Serial Number |
| `field_equipment_type` | taxonomy → `equipment_types` | Device Type | Device type (new IT terms, below) |
| `field_status` | taxonomy → `equipment_status` | Status | default **Active** (1301) |
| `field_date_purchased` | datetime | Date Purchased | (not in baseline — left empty) |
| `field_purchase_price` | decimal | Purchase Price | (not in baseline — left empty) |

> **Not reused:** `field_comments` is a Drupal **comment thread** type, not a
> notes field — do not use it for IT notes. `field_public_description` is a
> *public-facing* long-text field; IT notes contain sensitive posture (IPs,
> firewall/encryption state) and must **not** be public, so internal notes get a
> dedicated `field_it_notes` instead.

### Net-new IT fields (storages created; instanced only on `it_equipment`)

| Machine name | Type | Label | Baseline column |
|---|---|---|---|
| `field_it_hostname` | string | Hostname / Name | Computer Name / Hostname |
| `field_it_user` | string | Current User | Current User |
| `field_it_ipv4` | string | IPv4 Address | IPv4 |
| `field_it_mac` | string | MAC Address | MAC Address |
| `field_it_os` | string | Operating System | Operating System |
| `field_it_os_build` | string | OS Build | Build |
| `field_it_cpu` | string | Processor (CPU) | Processor |
| `field_it_ram_gb` | decimal (6,2) | RAM (GB) | RAM GB |
| `field_it_location` | string | Location | (area hints in device names) |
| `field_it_notes` | text_long | Role / Notes (internal) | Role / Notes, Evidence, Next Action |
| `field_it_disk_encryption` | string | OS Disk Encryption | OS Disk Encryption |
| `field_it_firewall` | string | Active Firewall | Active Firewall |
| `field_it_antivirus` | string | Antivirus Products | Antivirus Products |
| `field_it_time_sync` | string | Time Sync | Time Sync |
| `field_it_network_profile` | string | Network Profile | Network Profile |
| `field_it_workgroup` | string | Workgroup | Workgroup |
| `field_it_dhcp` | boolean | DHCP Enabled | DHCP (Yes/No) |
| `field_it_gateway` | string | Gateway | Gateway |
| `field_it_dns` | string | DNS Servers | DNS Servers |
| `field_it_link_speed` | string | Link Speed | Link Speed |

All field machine names are ≤ 32 chars (Drupal limit).

### New `equipment_types` taxonomy terms (Device Type)

Added so `field_equipment_type` can classify IT gear alongside physical
equipment: **Desktop PC / Workstation**, **NAS (Network Attached Storage)**,
**Network Switch**, **Router / Gateway**, **Printer**, **Unidentified Network
Device**.

## Source baseline → bundle

Source: `Brookstone_Office_Network_Baseline_v1.1.xlsx`.

- **Workstations tab** → 5 PCs, full detail (identity + specs + network +
  security posture).
- **Network Devices tab** → the non-PC assets (router/gateway `BUS-FW-01`, NAS
  `BUS-NAS-01`, unidentified interface `BUS-NET-UNKNOWN-01`, printers
  `BUS-PRN-*`, switches `BUS-SW-*`). Rows whose Asset ID starts `BUS-PC-` are
  **skipped here** — the Workstations tab already imported them with richer data.

Entity **label** = `"{Asset ID} — {hostname}"` (e.g. `BUS-PC-WS1 — OFFICE-WS1`).

## Import pipeline

Module **`bos_it_import`**, patterned after `bos_wex_import`: a Drush command
`it:import <file>` (CSV/XLSX via PhpSpreadsheet) → `equipment:it_equipment`
records. **Idempotent on `field_equipment_number` (Asset ID)** — re-running
updates the existing record instead of duplicating. Device-type text is
classified to a term by keyword; `Netgear GS605v5 (WS2 area)`-style names are
split into make/model + a location hint.

See `Modules/bos_it_import.md` (importer) and `bos_wex_import.md` (the pattern).

## Conventions followed

- ECK config naming per `01_entities_policy.md` (older
  `eck.eck_type.{type}.{bundle}` / `field.storage.{type}.{field}` /
  `field.field.{type}.{bundle}.{field}` pattern).
- Bundle + fields created via an **entity-API setup script**
  (`web/scripts/setup_it_equipment_bundle.php`) because ECK field configs
  silent-skip on `cim` — run once per environment. No existing equipment bundle
  is disturbed.
