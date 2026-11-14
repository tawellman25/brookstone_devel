# BOS SOP Authoring Workflow

## Structure

Each SOP lives in __BOS_AI/SOPs/[SOP_CODE]/ with three files (four since 2026-11-11):

  [SOP_CODE]_source.js              — Node.js script, generates the .docx
  [SOP_CODE]_HTML_Fields_PASTE.html — Field-by-field BOS paste file (human copy)
  [SOP_CODE]_v[VERSION].docx        — Generated output (never edit directly)
  [SOP_CODE]_fields.json            — The BOS field content, machine-readable.
                                      `drush php:script web/scripts/sop_import_drafts.php -- <file>.json`
                                      creates the SOP in BOS as a DRAFT (or refreshes an existing
                                      draft; never touches approved/active/archived). Parent and
                                      related SOPs are resolved by code. A human reviews in BOS and
                                      sets Active — codes lock at approval (GOV-SOP-001/003).

## Dependency

  ddev exec "npm install -g docx"
  ddev exec "NODE_PATH=/usr/local/lib/node_modules node -e 'require(\"docx\"); console.log(\"ok\")'"

Node lives inside DDEV (the WSL host has no node binary). The
docx module is installed globally in the DDEV web container at
/usr/local/lib/node_modules/docx — Node does not look there by
default, so NODE_PATH must be set when running source scripts.

## Creating a New SOP

Claude Chat provides the _source.js and _HTML_Fields_PASTE.html
content. Code writes them to the correct directory, runs the
source script to generate the .docx, and commits all three.

## Updating a SOP

1. Edit _source.js with content changes
2. Run:
   ddev exec "NODE_PATH=/usr/local/lib/node_modules \
     node /var/www/html/__BOS_AI/SOPs/[SOP_CODE]/[SOP_CODE]_source.js"
3. Update _HTML_Fields_PASTE.html to match
4. Bump version if substantive change
5. git add -A && git commit -m "Update [SOP_CODE]: [what changed]"

## Brand Colors

green #2E7D32 | green dark #1B5E20 | green light #E8F5E9
blue #0D47A1  | blue mid #1565C0   | blue light #E3F2FD
gold #F57F17  | gold light #FFF8E1
red #B71C1C   | red light #FFEBEE
gray #424242  | gray light #F5F5F5

## SOP Index

| Code            | Title                                              | Ver | Status |
|-----------------|----------------------------------------------------|-----|--------|
| OFF-ADM-EST-001 | Estimate Request Intake & Pipeline                 | 1.0 | Active |
| OFF-ADM-EST-002 | Estimate Stage Management (Estimator)              | 1.0 | Active |
| OFF-QBS-INV-003 | Printing Customer Invoices in QuickBooks Desktop   | 1.0 | Active |
| WEB-SYS-SGN-001  | Sign-Off: Confirm Crew Hours and Decide Who Gets Billed | 1.0 | Draft — entered in BOS 2026-11-11, awaiting Todd's review/Active |
| OFF-ADM-NBL-001  | Non-Billable Time: Review and Correction           | 1.0 | Draft — entered in BOS 2026-11-11, awaiting review |
| OFF-ADM-TGT-001  | Target Hours on Cleanup Work Orders                | 1.0 | Draft — entered in BOS 2026-11-11, awaiting review |
| WEB-SYS-CLK-001  | Clock In and Out on a Work Order: Yourself and Your Crew | 1.0 | Draft — entered in BOS 2026-11-13, awaiting review |
| MNT-PRT-001      | Shop Parts and Consumables: Add, Receive, Reorder, Retire | 1.0 | Draft — entered in BOS 2026-11-13, awaiting review |
| OFF-ADM-WTL-001  | Customer-Portal Waitlist: Finding and Working the Leads | 1.0 | Draft — entered in BOS 2026-11-13 (lead list view shipped with it) |
| OFF-ADM-SVR-001  | Service Requests: Review the Queue and Approve into a Work Order | 1.0 | Draft — entered in BOS 2026-11-13 |
| OFF-ADM-REV-001  | Customer Reviews: Entering, Approving and Handwritten Notes | 1.0 | Draft — entered in BOS 2026-11-13 |
| TRN-SVC-001      | Service Pages as the Crew Training Reference       | 1.0 | Draft — entered in BOS 2026-11-13 |
| IRR-MNT-BFT-001  | Backflow Device Field Test: Enter the Test, Sign Off, Tag the Device | 1.0 | Draft — entered in BOS 2026-11-13 |
| OFF-ADM-PRC-001  | Supplier Price Import: Review a Batch, Approve, and Clear the Queues | 1.0 | Draft — entered in BOS 2026-11-13; the workflow itself has not had a full production run yet |

> The three WO SOPs above were **drafted by Claude Code at Todd's explicit request on
> 2026-11-11** (job-clock guardrails items 5 + 6), as an exception to the "Chat authors,
> Code flags" rule below. Content is field-by-field per GOV-SOP-002 (MUST / MUST NOT;
> Pre-Checks · Steps · Quality Checks · Completion). All three source files were generated
> from one content definition (the session's `gen_sops.py`), so the docx, the paste HTML and
> the BOS entity say the same thing. Codes follow GOV-SOP-003 (OWNER-AREA-SERVICE-SEQ) and the per-bundle validators (system_procedures = WEB-SYS-…, office_administration = OFF-ADM-…):
> WEB = BOS system procedure used by every crew; OFF = office. Entered as **Draft** — Todd
> approves.
>
> **Second exception, 2026-11-13.** Todd: *"Please go ahead and create whatever SOPs that need
> created when it comes to BOS operations."* Code drafted the eight SOPs dated 2026-11-13 above —
> the two from that day's builds (crew clock morning pre-select, Equipment Parts) and the six that
> had sat on the SOP-Needed backlog since August–September. Every fact in them was extracted from
> the module docs and code the same evening (six parallel read-only passes; several corrected the
> backlog's own assumptions — e.g. the backflow button is "Enter Test Results", not "New Test";
> services use `field_service_crew_desc`, not the generic teammate field). **Codes differ from the
> backlog's suggestions because the per-bundle validators would have rejected them:** office SOPs
> must be `OFF-ADM-<SVC>-NNN`, `maintenance` and `training` allow exactly three segments
> (`MNT-PRT-001`, `TRN-SVC-001`), sprinkler maintenance is `IRR-MNT-<SVC>-NNN` and takes the
> service's own code (BFT). The generator is now committed — `__BOS_AI/SOPs/_gen/gen_sops.py` +
> `sop_content.py` — so docx, paste HTML and the BOS entity regenerate from one source
> (`python3 __BOS_AI/SOPs/_gen/gen_sops.py [CODE…]`, then the docx step below). Entered as
> **Draft**; Todd reviews each in BOS and sets Active.

## Pending SOPs (SOP-Needed backlog)

Flagged by Code when a human-facing workflow ships without an SOP. Claude
Chat authors these in a dedicated session, then they move up to the SOP Index.

_Cleared 2026-11-13 — the four items that sat here since August–September (service-page training,
service-request approval, portal waitlist, review entry) are now drafts in the SOP Index above, plus
the backflow field test (flagged in `Architecture/backflow_device_system.md`) and the supplier price
review (flagged in `Modules/supplier_price_ingest.md`). New flags go here as before._

| Suggested code  | Workflow                                           | Role  | Flagged     | Why |
|-----------------|----------------------------------------------------|-------|-------------|-----|
| _(none open)_   |                                                    |       |             |     |

---

## Entering drafts into BOS from the repo (2026-11-11)

    ddev drush php:script web/scripts/sop_import_drafts.php -- \
      /var/www/html/__BOS_AI/SOPs/[SOP_CODE]/[SOP_CODE]_fields.json [more .json …]

On live: scp the JSON(s) + `web/scripts/sop_import_drafts.php` to `~/tmp` and run the
same command with live's drush (`/opt/alt/php83/usr/bin/php vendor/drush/drush/drush.php`)
— the docroot is classifier-protected for writes, `~/tmp` is not. Idempotent on SOP code.

## SOP Maintenance Rules

### Auto-Update Rule

When Code makes a change to any BOS workflow that involves
human action (office staff, estimators, field crew), it must:

1. Check whether an SOP exists for that workflow:

       find __BOS_AI/SOPs -name "*_source.js" | xargs grep -l \
         "[workflow keyword]" 2>/dev/null

2. If an SOP exists and the change affects documented steps,
   warnings, KPIs, or validation behavior:
   - Update the relevant section in `[SOP_CODE]_source.js`
   - Regenerate the .docx:

         ddev exec "NODE_PATH=/usr/local/lib/node_modules \
           node /var/www/html/__BOS_AI/SOPs/[SOP_CODE]/[SOP_CODE]_source.js"

   - Update `[SOP_CODE]_HTML_Fields_PASTE.html` to match
   - Include the SOP files in the same git commit as the
     code change
   - Commit message format:
     `"Fix [thing]: [description] — update [SOP_CODE] to match"`

3. If an SOP exists but the change is minor (wording, not
   behavior): note it in the commit message but a full
   SOP update is not required.

### New SOP Flag Rule

When Code builds or significantly changes any of the following,
it must flag that an SOP may be needed:

- A new workflow that office staff interact with
- A new form or data entry process
- A new board, dashboard, or status pipeline
- A new automated action that staff need to understand
- A new validation or gate that blocks user actions
- A new role-based permission or access control

Flag format — include at the end of the completion report:

    ⚠ SOP NEEDED: [workflow name]
    Applies to: [role(s) — office staff / estimators / crew]
    Why: [one sentence describing what human action this involves]
    Suggested code: [OWNER]-[AREA]-[SERVICE]-[SEQ]
    Template: Request from Claude Chat to author this SOP

Code does NOT write the SOP content itself — it flags the
need and Claude Chat authors the SOP in a dedicated session.

### SOP Index Location

`__BOS_AI/SOPs/SOP-AUTHORING-WORKFLOW.md`

Update the SOP Index table in this file whenever a new SOP
is added or an existing one is updated.
