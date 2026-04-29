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

| Code            | Title                                 | Ver | Status |
|-----------------|---------------------------------------|-----|--------|
| OFF-ADM-EST-001 | Estimate Request Intake & Pipeline    | 1.0 | Active |
| OFF-ADM-EST-002 | Estimate Stage Management (Estimator) | 1.0 | Active |
