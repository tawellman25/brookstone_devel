# BOS Module — bos_it_import

**Package:** Work Orders · **Type:** utility / import · **Status:** built + tested
in DDEV 2026-08-01 (not yet on live)

## Purpose

Imports IT / computer assets (office PCs, NAS, network switches, router/gateway,
printers) from the **Office Network Baseline** workbook into the
`equipment:it_equipment` bundle. Patterned after `bos_wex_import`: a thin Drush
command over a transport-agnostic service that does all parse / map / upsert.

## Command

```
drush it:import <path-to-baseline.xlsx>     # alias of bos_it_import:import
```

- Reads two tabs: **Workstations** (rich PC rows) and **Network Devices**
  (gateway, NAS, switches, printers, unidentified interfaces).
- **Idempotent on Asset ID** (`field_equipment_number`, the `BUS-*` code):
  re-running updates the existing record instead of duplicating.
- Exit-code policy mirrors WEX: fail only on file-level problems (unreadable /
  parse error); per-row skips/errors are summarised, not fatal.

## Mapping highlights

- Workstations tab → full detail: identity, specs, network, and the security
  posture the baseline emphasises (disk encryption, firewall, antivirus, time
  sync, workgroup, DHCP, link speed).
- Network Devices tab → non-PC assets. Rows whose Asset ID starts `BUS-PC-` are
  **skipped** (already imported richer from Workstations).
- **Device type** resolved to an `equipment_types` term by **Asset-ID prefix**
  first (`BUS-PC-`→Workstation, `BUS-NAS-`→NAS, `BUS-SW-`→Switch, `BUS-FW-`→
  Router/Gateway, `BUS-PRN-`→Printer, `BUS-NET-UNKNOWN`→Unidentified), then by
  keyword fallback.
- A device **Name** like `Netgear GS605v5 (WS2 area)` is split into make
  (Netgear) + model (GS605v5) + location (WS2 area); a bare hostname
  (`HP37144F`) stays a hostname and make/model come from the device-type text
  (`HP DesignJet T210 24-in` → HP / DesignJet T210 24-in).
- Entity **title** = `"{Asset ID} — {hostname}"`. Default **status = Active**
  (1301) on create; never overrides a hand-set status on update.

## Gotcha captured during build

`equipment_types` uses **auto_entitylabel** (`[term:field_common_name]`), so new
terms must set **`field_common_name`**, not just `name` (setting `name` alone is
overwritten to empty on save). The setup script
(`web/scripts/setup_it_equipment_bundle.php`) creates the IT device-type terms
with `field_common_name` set.

## Related

- `__BOS_AI/Entities/equipment_it_equipment.md` — the bundle design (reused +
  net-new fields).
- `bos_wex_import.md` — the importer pattern this follows.
- `web/scripts/setup_it_equipment_bundle.php` — one-time-per-env bundle + fields
  + terms + displays setup.

## Deploy (pending)

Tested in DDEV only. Live rollout = `scp` the module + setup script → `drush en
bos_it_import` → run `setup_it_equipment_bundle.php` on live → `it:import` the
baseline → verify → `cr`. No `cim` (ECK/field configs silent-skip; the setup
script is the deploy path). Awaiting go-ahead.
