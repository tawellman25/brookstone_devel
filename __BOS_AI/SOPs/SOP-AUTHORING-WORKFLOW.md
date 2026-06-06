# BOS SOP Authoring Workflow

## Structure

Each SOP lives in __BOS_AI/SOPs/[SOP_CODE]/ with three files:

  [SOP_CODE]_source.js              — Node.js script, generates the .docx
  [SOP_CODE]_HTML_Fields_PASTE.html — Field-by-field BOS paste file
  [SOP_CODE]_v[VERSION].docx        — Generated output (never edit directly)

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

---

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
