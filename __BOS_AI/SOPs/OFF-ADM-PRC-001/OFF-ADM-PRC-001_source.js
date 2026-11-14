const {
  Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
  HeadingLevel, AlignmentType, BorderStyle, WidthType, ShadingType,
  LevelFormat, Header, Footer, TabStopType, TabStopPosition
} = require('docx');
const fs = require('fs');

// ── Brand Colors ─────────────────────────────────────────────────────────────
const C = {
  green:      '2E7D32', greenDk:   '1B5E20', greenLt:   'E8F5E9',
  blue:       '0D47A1', blueMid:   '1565C0', blueLt:    'E3F2FD',
  gold:       'F57F17', goldLt:    'FFF8E1',
  red:        'B71C1C', redLt:     'FFEBEE',
  gray:       '424242', grayLt:    'F5F5F5', grayMid:   'EEEEEE',
  white:      'FFFFFF', border:    'CCCCCC',
};

// ── Border helpers ───────────────────────────────────────────────────────────
const bdr = (c = C.border) => ({ style: BorderStyle.SINGLE, size: 1, color: c });
const borders = (c = C.border) => ({ top: bdr(c), bottom: bdr(c), left: bdr(c), right: bdr(c) });
const noBorders = () => ({ top: bdr('FFFFFF'), bottom: bdr('FFFFFF'), left: bdr('FFFFFF'), right: bdr('FFFFFF') });

// ── Cell helper ──────────────────────────────────────────────────────────────
const cell = (children, { w = 4680, fill, bc = C.border, nb = false, vAlign = 'top', cs } = {}) =>
  new TableCell({
    columnSpan: cs,
    borders: nb ? noBorders() : borders(bc),
    width: { size: w, type: WidthType.DXA },
    shading: fill ? { fill, type: ShadingType.CLEAR } : undefined,
    margins: { top: 90, bottom: 90, left: 140, right: 140 },
    verticalAlign: vAlign,
    children: Array.isArray(children) ? children : [children],
  });

// ── Paragraph helpers ────────────────────────────────────────────────────────
const p = (text, { bold, italic, color, size = 22, before = 0, after = 120, align, heading, border: pb } = {}) =>
  new Paragraph({
    heading,
    alignment: align,
    spacing: { before, after },
    border: pb,
    children: Array.isArray(text) ? text : [new TextRun({ text, bold, italics: italic, color, size, font: 'Arial' })],
  });

const run = (text, { bold, italic, color, size = 22, break: br } = {}) =>
  new TextRun({ text, bold, italics: italic, color, size, font: 'Arial', break: br ? 1 : 0 });

const spacer = (before = 80, after = 80) => p('', { before, after });
const divider = (color = C.green) => new Paragraph({
  spacing: { before: 120, after: 120 },
  border: { bottom: { style: BorderStyle.SINGLE, size: 6, color, space: 1 } },
  children: [new TextRun({ text: '', font: 'Arial', size: 4 })],
});

const bullet = (text, level = 0) => new Paragraph({
  numbering: { reference: 'bullets', level },
  spacing: { before: 30, after: 30 },
  children: Array.isArray(text) ? text : [new TextRun({ text, font: 'Arial', size: 22 })],
});

const numbered = (text, level = 0) => new Paragraph({
  numbering: { reference: 'numbers', level },
  spacing: { before: 30, after: 30 },
  children: Array.isArray(text) ? text : [new TextRun({ text, font: 'Arial', size: 22 })],
});

// ── Section header ───────────────────────────────────────────────────────────
const sectionHdr = (label) => new Paragraph({
  spacing: { before: 320, after: 140 },
  border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: C.green, space: 4 } },
  children: [new TextRun({ text: label, bold: true, color: C.greenDk, size: 28, font: 'Arial' })],
});

// ── Pipeline flow ────────────────────────────────────────────────────────────
const pipelineRow = (steps) => {
  const fills = ['1565C0','006064','4A148C','E65100','424242'];
  const rows = [];
  steps.forEach((s, i) => {
    rows.push(new TableRow({ children: [
      cell(p([run(`${i+1}. `, { bold: true, color: C.white, size: 20 }), run(s.label, { bold: true, color: C.white, size: 20 })], { align: AlignmentType.CENTER, after: 0 }), { w: 2200, fill: fills[i], bc: fills[i] }),
      cell(p(s.desc, { color: C.gray, size: 21, after: 0 }), { w: 7160 }),
    ]}));
    if (i < steps.length - 1) rows.push(new TableRow({ children: [
      cell(p('▼', { align: AlignmentType.CENTER, color: C.green, size: 18, after: 0, before: 0 }), { w: 2200, nb: true }),
      cell(p('', { after: 0 }), { w: 7160, nb: true }),
    ]}));
  });
  return new Table({ width: { size: 9360, type: WidthType.DXA }, columnWidths: [2200, 7160], rows });
};

// ── Step table ───────────────────────────────────────────────────────────────
const stepTable = (steps) => {
  const rows = [];
  steps.forEach(s => {
    rows.push(new TableRow({ children: [
      cell(p(String(s.num), { bold: true, color: C.white, size: 24, align: AlignmentType.CENTER, after: 0 }), { w: 640, fill: C.green, bc: C.green, vAlign: 'center' }),
      cell(p(s.title, { bold: true, color: C.greenDk, size: 22, after: 0 }), { w: 6400, fill: C.greenLt, bc: C.green }),
      cell(p(s.who, { italic: true, color: C.blue, size: 19, align: AlignmentType.RIGHT, after: 0 }), { w: 2320, fill: C.greenLt, bc: C.green }),
    ]}));
    rows.push(new TableRow({ children: [
      cell(p('', { after: 0 }), { w: 640, fill: C.grayLt, bc: C.border }),
      cell(s.items.map(item =>
        Array.isArray(item)
          ? new Paragraph({ numbering: { reference: 'bullets', level: 0 }, spacing: { before: 24, after: 24 }, children: item })
          : bullet(item)
      ), { w: 8720, bc: C.border, cs: 2 }),
    ]}));
  });
  return new Table({ width: { size: 9360, type: WidthType.DXA }, columnWidths: [640, 6400, 2320], rows });
};

// ── Warning / info box ───────────────────────────────────────────────────────
const alertBox = (icon, title, text, fill, titleColor, bc) =>
  new Table({
    width: { size: 9360, type: WidthType.DXA }, columnWidths: [9360],
    rows: [new TableRow({ children: [cell([
      p([run(`${icon}  ${title}`, { bold: true, color: titleColor, size: 22 })], { after: 60 }),
      p(text, { size: 22, after: 0 }),
    ], { w: 9360, fill, bc })] })],
  });

const warn  = (t, x) => alertBox('⚠', t, x, C.goldLt, C.gold, C.gold);
const block = (t, x) => alertBox('✕', t, x, C.redLt,  C.red,  C.red);
const info  = (t, x) => alertBox('ℹ', t, x, C.blueLt, C.blue, C.blue);

// ── Do / Don't table ─────────────────────────────────────────────────────────
const dodonts = (pairs) => {
  const rows = [new TableRow({ children: [
    cell(p("✕  Don't", { bold: true, color: C.white, size: 22, after: 0 }), { w: 4680, fill: C.red, bc: C.red }),
    cell(p("✓  Do", { bold: true, color: C.white, size: 22, after: 0 }), { w: 4680, fill: C.green, bc: C.green }),
  ]})];
  pairs.forEach(([bad, good]) => rows.push(new TableRow({ children: [
    cell(p(bad, { color: '5D4037', size: 20, after: 0 }), { w: 4680, bc: 'FFCDD2' }),
    cell(p(good, { color: '1B5E20', size: 20, after: 0 }), { w: 4680, bc: 'C8E6C9' }),
  ]})));
  return new Table({ width: { size: 9360, type: WidthType.DXA }, columnWidths: [4680, 4680], rows });
};

// ── KPI table ────────────────────────────────────────────────────────────────
const kpiTable = (kpis) => {
  const rows = [new TableRow({ children: [
    cell(p('Indicator', { bold: true, color: C.white, size: 20, after: 0 }), { w: 4680, fill: C.greenDk, bc: C.greenDk }),
    cell(p('Target / Standard', { bold: true, color: C.white, size: 20, after: 0 }), { w: 2880, fill: C.greenDk, bc: C.greenDk }),
    cell(p('Owner', { bold: true, color: C.white, size: 20, after: 0 }), { w: 1800, fill: C.greenDk, bc: C.greenDk }),
  ]})];
  kpis.forEach((k, i) => rows.push(new TableRow({ children: [
    cell(p(k.indicator, { size: 20, after: 0 }), { w: 4680, fill: i % 2 === 0 ? C.white : C.grayLt }),
    cell(p(k.target, { size: 20, after: 0 }), { w: 2880, fill: i % 2 === 0 ? C.white : C.grayLt }),
    cell(p(k.owner, { size: 20, italic: true, color: C.blue, after: 0 }), { w: 1800, fill: i % 2 === 0 ? C.white : C.grayLt }),
  ]})));
  return new Table({ width: { size: 9360, type: WidthType.DXA }, columnWidths: [4680, 2880, 1800], rows });
};

// ─────────────────────────────────────────────────────────────────────────────
// DOCUMENT

// ─────────────────────────────────────────────────────────────────────────────
// DOCUMENT — OFF-ADM-PRC-001 (Supplier Price Import: Review a Batch, Approve, and Clear the Queues) · generated 2026-11-13 by Code at Todd's
// request; the entity content lives in OFF-ADM-PRC-001_fields.json (same source).
// ─────────────────────────────────────────────────────────────────────────────
const doc = new Document({
  numbering: {
    config: [
      { reference: 'bullets', levels: [
        { level: 0, format: LevelFormat.BULLET, text: '•', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 440, hanging: 280 } } } },
        { level: 1, format: LevelFormat.BULLET, text: '◦', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 880, hanging: 280 } } } },
      ]},
      { reference: 'numbers', levels: [
        { level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 440, hanging: 280 } } } },
      ]},
    ],
  },
  styles: {
    default: { document: { run: { font: 'Arial', size: 22, color: '212121' } } },
    paragraphStyles: [
      { id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { size: 36, bold: true, color: C.greenDk, font: 'Arial' }, paragraph: { spacing: { before: 360, after: 160 }, outlineLevel: 0 } },
      { id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { size: 26, bold: true, color: C.green, font: 'Arial' }, paragraph: { spacing: { before: 240, after: 100 }, outlineLevel: 1 } },
    ],
  },
  sections: [{
    properties: { page: { size: { width: 12240, height: 15840 }, margin: { top: 1080, right: 1080, bottom: 1080, left: 1080 } } },
    headers: { default: new Header({ children: [new Paragraph({
      spacing: { before: 0, after: 80 },
      border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 } },
      children: [run('BROOKSTONE OUTDOORS  ', { bold: true, color: C.green, size: 18 }), run("|  OFF-ADM-PRC-001  |  Supplier Price Import: Review a Batch, Approve, and Clear the Queues", { color: '757575', size: 18 })],
    })]}) },
    footers: { default: new Footer({ children: [new Paragraph({
      spacing: { before: 80, after: 0 },
      border: { top: { style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 } },
      tabStops: [{ type: TabStopType.RIGHT, position: TabStopPosition.MAX }],
      children: [run("OFF-ADM-PRC-001 v1.0  ·  November 2026  ·  Internal Use Only  ", { color: '9E9E9E', size: 18 }), run("\tOffice Administration SOP", { color: '9E9E9E', size: 18 })],
    })]}) },
    children: [
      spacer(0, 480),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 40 }, children: [run('BROOKSTONE OUTDOORS', { bold: true, color: C.green, size: 52 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 160 }, children: [run('Creating and Maintaining Your Outdoor Spaces', { italic: true, color: '757575', size: 24 })] }),
      divider(), spacer(120, 80),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 60 }, children: [run('STANDARD OPERATING PROCEDURE', { bold: true, color: C.greenDk, size: 36 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 240 }, children: [run("OFF-ADM-PRC-001 — Supplier Price Import: Review a Batch, Approve, and Clear the Queues", { bold: true, color: C.gray, size: 28 })] }),
      new Table({ width: { size: 5040, type: WidthType.DXA }, columnWidths: [2000, 3040], rows: [
        new TableRow({ children: [cell(p("SOP Code", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("OFF-ADM-PRC-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Bundle", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("office_administration", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Version", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("1.0", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Effective", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("November 2026 (Draft until approved)", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Applies To", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("Office manager (Office Admin role); Todd for the first production runs", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Related SOPs", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("OFF-ADM-EST-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Governance", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("GOV-SOP-001 · GOV-SOP-002 · GOV-SOP-003", { size: 20, after: 0 }), { w: 3040 })] })
      ]}),
      spacer(240, 0), divider(), spacer(200, 0),

      sectionHdr('1  Purpose'),
      info("Why This SOP Exists", "Material prices in BOS feed every estimate and every work order materials list. The supplier price import reads a supplier's catalog file, matches each line to our materials, shows a dry-run report of what would change, and writes the catalog only when the office approves. Lines it cannot match wait in two queues for a person to decide. This SOP exists so an approval is a read decision, not a reflex; so a large price jump is held for review rather than applied; and so unmatched lines are resolved without creating duplicate materials. The pipeline is built and live but has not yet been run against a full real supplier catalog — the first runs follow the extra rails in this SOP."),
      spacer(200, 0),

      sectionHdr('2  Scope'),
      p([run('Applies to', { bold: true, color: C.green })], { after: 60 }),
      bullet("Every supplier catalog file uploaded to Supplier Price Ingest"),
      bullet("The dry-run report, Approve and Commit, Reject Batch"),
      bullet("The Discovery Queue and Fuzzy Match Review after a commit"),
      bullet("Price-review items the commit holds back"),
      p([run('Does not apply to', { bold: true, color: C.red })], { before: 120, after: 60 }),
      bullet("Supplier configuration (the parsing rules per supplier) — set up by admin; do not edit"),
      bullet("Material records themselves, pack families and bundles — material administration"),
      bullet("Work order and estimate material snapshots — never changed by an import"),
      bullet("Fuel (WEX) and shop parts — separate systems"),
      spacer(200, 0),

      sectionHdr('3  Rules & Responsibilities'),
      p([run('MUST', { bold: true, color: C.white, size: 20 })], { after: 60, border: { left: { style: BorderStyle.SINGLE, size: 24, color: C.green, space: 6 } } }),
      bullet("The office manager must read the dry-run report before approving: the match summary by tier, the price-change impact, and the sample rows. Approve is a decision about those numbers."),
      bullet("The first production run of any supplier must be a small file (20–30 rows) agreed with Todd, after a database snapshot, with the same file run in the development copy first. Only after it checks out does a full catalog run."),
      bullet("After every commit the office manager must spot-check: open a handful of the auto-applied materials, confirm the supplier link price and the price-history line \"Updated from ingest batch #N\", then review the items the commit held in Price Review."),
      bullet("Rows in the Fuzzy Match Review must be confirmed only when the proposed material is the same product in the same unit of measure; anything else is Override or Send to Discovery."),
      bullet("Rows in the Discovery Queue must be linked to an existing material when one exists. Create New Material only after searching, and never for a discontinued product — use Mark as Replacement."),
      bullet("A rejected batch or row must carry the reason in its notes. Reject Batch cannot be undone."),
      p([run('MUST NOT', { bold: true, color: C.red, size: 20 })], { before: 160, after: 60 }),
      bullet("Do not approve a batch whose report shows errors or an unexpected number of \"requires review\" or discovery rows without asking why. A supplier changed a column, or the wrong file was uploaded."),
      bullet("Do not upload the same file twice \"to be sure\". There is no duplicate guard; a second upload is a second batch to reject."),
      bullet("Do not create a new material for a line whose description matches an existing one in a different pack size — link it, so the pack tiers stay on one record."),
      bullet("Do not expect an undo. Committed prices are written to the supplier links and the price history; corrections are made forward, by hand or by the next import."),
      spacer(200, 0),

      sectionHdr('4  Prerequisites'),
      bullet("Office Admin role (administer supplier price ingest — site admin alone does not have it)"),
      bullet("An active supplier configuration for the supplier of the file"),
      bullet("The supplier's catalog file: CSV, XLS or XLSX, under 50 MB"),
      bullet("First production run of a supplier: Todd's go, a fresh database snapshot, and the same file already run in development"),
      spacer(200, 0),

      sectionHdr('5  Steps & Procedures'),
      p([run('Overview', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      pipelineRow([{ label: "Upload", desc: "Upload Catalog → supplier, file → Upload + parse." }, { label: "Dry run", desc: "Batch page: match summary, price impact, samples." }, { label: "Approve", desc: "Approve and Commit — or Reject Batch." }, { label: "Verify", desc: "Spot-check materials, price history, Price Review." }, { label: "Queues", desc: "Fuzzy Match Review, then Discovery Queue — Resolve each row." }]),
      spacer(160, 0),
      p([run('Pre-Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("The file is from the supplier you will select, in the format their configuration expects."),
      bullet("Nothing is pending in Price Review from a previous batch (or you know what is)."),
      bullet("First run only: snapshot taken, development run reviewed, Todd aware."),
      spacer(120, 0),
      p([run('Steps', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      stepTable([
        { num: 1, title: "Upload", who: "Office manager", items: ["Admin ▸ Materials ▸ Supplier Price Ingest ▸ Upload Catalog (/admin/materials/supplier-ingest/upload). Choose the Supplier (only suppliers with an active configuration are listed), the Source file, add Notes (where the file came from, which run this is). Press Upload + parse.", "The batch opens at Pending Dry Run and refreshes itself until the dry run is complete. Nothing has been written to the catalog."] },
        { num: 2, title: "Read the dry-run report", who: "Office manager", items: ["Match summary: Tier 1 (manufacturer + item number), Tier 2 (known supplier SKU) and Tier 3 high (strong description match) say ✓ will auto-apply. Tier 3 medium and ambiguous Tier 1 say ⚠ requires review. Discovery rows route to discovery queue. Skipped rows (discontinued, excluded bundle, do-not-use) take no action. Errors are listed — an unrecognized unit of measure is the usual one.", "Price change impact (auto-apply rows only): new supplier links to create; existing links changing within ±10% — will apply; increases of 10% or more — held for Price Review. If the report says the impact took too long, use Export Detailed Report (CSV).", "Sample rows per tier (up to 10) and the discovery breakdown by inferred bundle. Open a few samples: is the match the same product?"] },
        { num: 3, title: "Approve or reject", who: "Office manager", items: ["Report as expected: press Approve and Commit. The confirmation names the counts and warns that a batch of 500 rows or more runs on a progress page — stay on it. On commit BOS, per auto-apply row: creates the supplier link if none exists, or updates the link's unit cost, effective date (today), source and notes; fills the supplier SKU if it was empty; writes a price-history line naming the batch; holds any increase of 10% or more in Price Review instead of applying it; and recomputes the material's cost. Decreases always apply.", "Wrong file, wrong supplier, or a report you do not understand: press Reject Batch and write why in the batch notes. It marks the batch and every row rejected, writes nothing, keeps the batch for audit, and cannot be undone."] },
        { num: 4, title: "Verify the commit", who: "Office manager", items: ["The committed batch shows a banner with the pending counts for both queues. Open five to twenty of the auto-applied materials: the supplier link shows the new cost and today's date; the price history shows \"Updated from ingest batch #N\".", "Admin ▸ Materials ▸ Price Review (/admin/materials/price-review): each held increase of 10% or more. Accept or reject each one on its merits — a real list-price rise versus a pack-size or unit error.", "First production run: watch estimates and material lists for 48 hours; report anything odd to Todd."] },
        { num: 5, title: "Fuzzy Match Review", who: "Office manager", items: ["Supplier Price Ingest ▸ Fuzzy Match Review: rows the matcher scored medium (70–89), Tier 1.5 substring matches, and ambiguous matches with several candidates, highest confidence first. Filter by batch.", "Open a row with Resolve →. Choose Confirm proposed match (commits that row now), Override — link to a different material, Send to Discovery Queue, or Reject row. Confirming a discontinued material is blocked. Save and load next row moves on; Ctrl/Cmd+Enter submits, Esc returns to the queue."] },
        { num: 6, title: "Discovery Queue", who: "Office manager", items: ["Supplier Price Ingest ▸ Discovery Queue: rows with no match, each with a note naming the best candidate. Filter by batch, description or inferred bundle.", "Resolve → each row: Link to Existing Material (search first — same product, any pack size); Create New Material (bundle is pre-filled from inference; give it the title, manufacturer item number, manufacturer and unit) only when nothing exists; Mark as Replacement when the line replaces a discontinued material (pick the discontinued one, then an existing or new replacement); Reject row with notes for lines we do not stock.", "Bulk Reject exists on both queues for a run of lines we will never carry. There is no bulk create or link."] }
      ]),
      spacer(160, 0),
      block("Reject Batch and committed prices cannot be undone", "Reject marks every row rejected and is final. A commit writes supplier links and price history with no rollback button. Read before you press, and run a new supplier small and in development first."),
      spacer(80, 0),
      warn("Not yet a routine", "The pipeline has been dry-run on partial scrapes in development but never on a full real catalog in production. Until the first full run has been verified, every run is a supervised run: small file, snapshot, development first, 48-hour watch."),
      spacer(80, 0),
      info("What the dry run counts versus what the commit does", "The dry run counts price drops of more than 10% among \"will queue\"; the commit applies all decreases and holds only increases of 10% or more. If the Price Review list is shorter than the report suggested, that is why."),
      spacer(160, 0),
      p([run('Quality Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("Every approved batch has a dry-run report that was read, and notes saying who ran it and why."),
      bullet("Spot-checked materials show the new supplier-link cost and a price-history line naming the batch."),
      bullet("Price Review is empty, or every held item has a decision, within two working days of a commit."),
      bullet("Both queues are empty for the batch within a week; no new material was created where one already existed."),
      p([run('Completion', { bold: true, color: C.greenDk, size: 24 })], { before: 120, after: 60 }),
      bullet("Batch Committed; verification done; Price Review cleared; queues resolved."),
      bullet("Notes on the batch: file source, run number, anything odd and what was done about it."),
      spacer(160, 0),
      dodonts([["Approve because the dry run finished without errors", "Read the tier counts, the price impact and the samples first"], ["Upload again when the page is slow", "Wait — Pending Dry Run refreshes itself; a second upload is a second batch"], ["Create a new material for \"PVC 3/4 in, case of 50\"", "Link it to the existing 3/4 in PVC material"], ["Confirm a fuzzy match because the words look close", "Same product, same unit, or Override / Discovery"]]),
      spacer(200, 0),

      sectionHdr('6  Tools & Resources'),
      bullet("Admin ▸ Materials ▸ Supplier Price Ingest — Upload Catalog, Batches, Discovery Queue, Fuzzy Match Review"),
      bullet("Batch page — dry-run report, Approve and Commit, Reject Batch, Export Detailed Report (CSV)"),
      bullet("Admin ▸ Materials ▸ Price Review — increases of 10% or more held by a commit"),
      bullet("Material record — supplier links and price history"),
      bullet("Modules/supplier_price_ingest.md and sequencing.md — the first-run plan"),
      spacer(200, 0),

      sectionHdr('7  Key Performance Indicators'),
      kpiTable([{ indicator: "Batches approved with the dry-run report read and notes written", target: "100%", owner: "Office manager" }, { indicator: "Price Review items decided within 2 working days of a commit", target: "100%", owner: "Office manager" }, { indicator: "Duplicate materials created from the Discovery Queue", target: "0 — checked on the quarterly material audit", owner: "Office manager" }, { indicator: "Queue rows unresolved after 7 days", target: "0", owner: "Office manager" }, { indicator: "Rejected batches without a reason in notes", target: "0", owner: "Office manager" }]),
      spacer(200, 0),

      sectionHdr('8  Related SOPs'),
      bullet("OFF-ADM-EST-001 — Estimate Request Intake & Pipeline"),
      bullet("GOV-SOP-001 — SOP Creation & Management"),
      spacer(200, 80), divider(), spacer(80, 0),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 0 }, children: [run("Brookstone Outdoors LLC  ·  OFF-ADM-PRC-001 v1.0  ·  November 2026  ·  Internal Use Only", { color: '9E9E9E', size: 18, italic: true })] }),
    ],
  }],
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync(__dirname + '/OFF-ADM-PRC-001_v1.0.docx', buf);
  console.log('Done OFF-ADM-PRC-001');
});
