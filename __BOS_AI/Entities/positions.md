# BOS Entity — Positions

Entity Type ID: `positions`
Storage: ECK

## Purpose
Defines job roles within Brookstone Outdoors. Each position represents a
distinct role in the organizational hierarchy with defined responsibilities,
qualifications, reporting structure, and crew assignment.

Positions are referenced by:
- `sop.field_required_positions` (training bundle) — which positions a training SOP applies to
- `teammate_profile` — job title/position assignment (via `field_job_title`)

## Bundle
`role` — single bundle for all positions

## Current Positions (20)

### Leadership
| ID | Title | Reports To | Crew | FLSA |
|---|---|---|---|---|
| 1 | Founding Partners | — | — | — |
| 2 | Operations Manager | Founding Partners | — | — |

### Office
| ID | Title | Reports To | Crew | FLSA |
|---|---|---|---|---|
| 3 | Office Manager | Founding Partners | Office Admin | — |
| 4 | Office Assistant | Office Manager | Office Admin | Non-exempt |
| 5 | Sales Representative | Founding Partners | — | Exempt |
| 8 | Bookkeeper | Office Manager | Office Admin | Non-exempt |

### Field — Supervisor + Technician pairs
| ID | Title | Reports To | Crew | FLSA |
|---|---|---|---|---|
| 6 | Landscape Supervisor | Founding Partners | Landscape Crew | Non-exempt |
| 7 | Landscape Technician | Landscape Supervisor | Landscape Crew | Non-exempt |
| 9 | Clean-up Supervisor | Founding Partners | Clean-up Crew | Non-exempt |
| 10 | Clean-up Technician | Clean-up Supervisor | Clean-up Crew | Non-exempt |
| 11 | Fertilizing Supervisor | Founding Partners | Fertilizing Crew | Non-exempt |
| 12 | Fertilizing Technician | Fertilizing Supervisor | Fertilizing Crew | — |
| 13 | Irrigation Supervisor | Founding Partners | Irrigation Crew | Non-exempt |
| 14 | Irrigation Technician | Irrigation Supervisor | Irrigation Crew | — |
| 15 | Lawn Maintenance Supervisor | Founding Partners | Lawn Maintenance Crew | — |
| 16 | Lawn Maintenance Technician | Lawn Maintenance Supervisor | Lawn Maintenance Crew | Non-exempt |
| 17 | Snow Removal Supervisor | Founding Partners | Snow Removal Crew | Non-exempt |
| 18 | Snow Removal Technician | Snow Removal Supervisor | Snow Removal Crew | Non-exempt |
| 19 | Spray Supervisor | Founding Partners | Spray Crew | Non-exempt |
| 20 | Spray Technician | Spray Supervisor | Spray Crew | Non-exempt |

---

## Fields

### Organizational
- `title` (base) — position title
- `field_reporting_structure` → `positions` (self-referential) — who this position reports to
- `field_associated_crew` → `crew_types` — which crew this position belongs to
- `field_flsa_status` — list_string: `exempt` | `non_exempt` (Fair Labor Standards Act classification)

### Job Description
- `field_job_summary` — text_long: overview of the role
- `field_key_responsibilities` — text_long: primary duties and accountabilities
- `field_qualifications` — text_long: required education, experience, certifications
- `field_physical_requirements` — text_long: physical demands of the role
- `field_work_environment` — string: work environment description
- `field_skills_certifications` → taxonomy_term (`crew_skills_and_certifications`) — required skills and certifications (53 terms available)

---

## Organizational Pattern

Brookstone follows a consistent Supervisor + Technician pattern per department:
- Each crew type has one Supervisor and one or more Technician positions
- Supervisors report to Founding Partners
- Technicians report to their department Supervisor
- Each position is linked to its crew via `field_associated_crew`

Office positions follow a separate hierarchy:
- Office Manager and Sales Rep report to Founding Partners
- Office Assistant and Bookkeeper report to Office Manager

---

## Referenced By

| Entity | Field | Purpose |
|---|---|---|
| `sop` (training bundle) | `field_required_positions` | Which positions a training SOP applies to |
| `teammate_profile` | `field_job_title` | Employee's assigned position |

---

## Invariants
- Positions are reference data — do not delete positions that are referenced by profiles or SOPs
- The Supervisor → Technician hierarchy must be maintained per department
- `field_reporting_structure` creates the org chart — keep it accurate

Created: April 2026
