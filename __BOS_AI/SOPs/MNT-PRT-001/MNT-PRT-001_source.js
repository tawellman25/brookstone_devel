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
// DOCUMENT — MNT-PRT-001 (Shop Parts and Consumables: Add, Receive, Reorder, Retire) · generated 2026-11-13 by Code at Todd's
// request; the entity content lives in MNT-PRT-001_fields.json (same source).
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
      children: [run('BROOKSTONE OUTDOORS  ', { bold: true, color: C.green, size: 18 }), run("|  MNT-PRT-001  |  Shop Parts and Consumables: Add, Receive, Reorder, Retire", { color: '757575', size: 18 })],
    })]}) },
    footers: { default: new Footer({ children: [new Paragraph({
      spacing: { before: 80, after: 0 },
      border: { top: { style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 } },
      tabStops: [{ type: TabStopType.RIGHT, position: TabStopPosition.MAX }],
      children: [run("MNT-PRT-001 v1.0  ·  November 2026  ·  Internal Use Only  ", { color: '9E9E9E', size: 18 }), run("\tMaintenance SOP", { color: '9E9E9E', size: 18 })],
    })]}) },
    children: [
      spacer(0, 480),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 40 }, children: [run('BROOKSTONE OUTDOORS', { bold: true, color: C.green, size: 52 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 160 }, children: [run('Creating and Maintaining Your Outdoor Spaces', { italic: true, color: '757575', size: 24 })] }),
      divider(), spacer(120, 80),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 60 }, children: [run('STANDARD OPERATING PROCEDURE', { bold: true, color: C.greenDk, size: 36 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 240 }, children: [run("MNT-PRT-001 — Shop Parts and Consumables: Add, Receive, Reorder, Retire", { bold: true, color: C.gray, size: 28 })] }),
      new Table({ width: { size: 5040, type: WidthType.DXA }, columnWidths: [2000, 3040], rows: [
        new TableRow({ children: [cell(p("SOP Code", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("MNT-PRT-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Bundle", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("maintenance", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Version", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("1.0", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Effective", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("November 2026 (Draft until approved)", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Applies To", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("Shop / mechanic, supervisors; office for ordering", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Related SOPs", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("MNT-FLT-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Governance", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("GOV-SOP-001 · GOV-SOP-002 · GOV-SOP-003", { size: 20, after: 0 }), { w: 3040 })] })
      ]}),
      spacer(240, 0), divider(), spacer(200, 0),

      sectionHdr('1  Purpose'),
      info("Why This SOP Exists", "The shop keeps spark plugs, filters, carb kits, trimmer line, blades, belts, oil and hardware for our own machines. BOS now holds one record per part — what it fits, where it sits on the shelf, how many we have and when to reorder — so a mechanic can answer \"what does this HS 82 take and do we have one\" from the equipment page, and the office can order from the Reorder list instead of a note on the wall. These parts are shop stock for our equipment. They are never customer materials and never appear on an estimate or a work order materials list."),
      spacer(200, 0),

      sectionHdr('2  Scope'),
      p([run('Applies to', { bold: true, color: C.green })], { after: 60 }),
      bullet("Every part or consumable kept in the shop for Brookstone-owned equipment"),
      bullet("Whoever adds a part, puts stock on the shelf, pulls stock for a repair, or orders"),
      bullet("Parts Needed on an equipment defect and Parts Used on a maintenance event"),
      p([run('Does not apply to', { bold: true, color: C.red })], { before: 120, after: 60 }),
      bullet("Customer materials (pipe, fittings, plants, rock) — those are Materials, with their own pricing and supplier links"),
      bullet("Fuel — fuel transactions come from the WEX import"),
      bullet("Equipment itself — vehicles, mowers and tools are Equipment records"),
      bullet("Repairs done by an outside shop — their invoice goes on the maintenance event"),
      spacer(200, 0),

      sectionHdr('3  Rules & Responsibilities'),
      p([run('MUST', { bold: true, color: C.white, size: 20 })], { after: 60, border: { left: { style: BorderStyle.SINGLE, size: 24, color: C.green, space: 6 } } }),
      bullet("Every part on the shelf must have one record, found by its part number. Search before adding: BOS refuses a second record with the same number, in any spacing or case, and names the existing part."),
      bullet("Each record must carry the part number as printed on the package, a category, at least one equipment type it fits, and a bin location. Add the units it fits when you know them."),
      bullet("Whoever puts stock on the shelf must update On Hand, Last Price, Date Priced and Vendor on the part the same day."),
      bullet("Whoever pulls a part for a repair must lower On Hand and add the part to the maintenance event's Parts Used. BOS does not reduce stock on its own yet."),
      bullet("The office must order from the Reorder list — parts whose On Hand is at or below their Reorder Minimum — not from memory."),
      bullet("A part we no longer stock must be retired by unticking Active (stocked). Records are never deleted; the history of what fit which machine stays."),
      p([run('MUST NOT', { bold: true, color: C.red, size: 20 })], { before: 160, after: 60 }),
      bullet("Do not add a shop part as a Material. It would appear in every estimate and work order materials picker."),
      bullet("Do not set a Reorder Minimum you do not mean. A minimum of 0 means \"not tracked\" and the part never flags."),
      bullet("Do not leave a defect's Parts Needed empty when you know what the repair takes — that is how the office knows what to order before the machine is on the bench."),
      bullet("Do not delete a part record, even a mistaken one. Retire it and note why."),
      spacer(200, 0),

      sectionHdr('4  Prerequisites'),
      bullet("BOS account with the supervisor or office role (teammates can look parts up but not add or edit them)"),
      bullet("The part in hand, or its number from the package or the parts diagram"),
      bullet("The machine, or its equipment record, when adding what the part fits"),
      spacer(200, 0),

      sectionHdr('5  Steps & Procedures'),
      p([run('Overview', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      pipelineRow([{ label: "Look up", desc: "Equipment page → Parts that fit this unit, or Parts list → search by number." }, { label: "Add", desc: "Add part: number, name, category, fits, bin, minimum." }, { label: "Receive", desc: "Stock arrives: On Hand up, Last Price, Date Priced, Vendor." }, { label: "Use", desc: "Repair: On Hand down, Parts Used on the event." }, { label: "Reorder", desc: "Reorder list tab → order → receive." }]),
      spacer(160, 0),
      p([run('Pre-Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("You searched the parts list by part number and the part is not already there."),
      bullet("You know at least one equipment type the part fits."),
      bullet("For receiving: the packing slip or receipt is in front of you."),
      spacer(120, 0),
      p([run('Steps', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      stepTable([
        { num: 1, title: "Find what fits a machine", who: "Shop / mechanic", items: ["Open the equipment record (scan its QR or search Equipment). At the bottom, Parts that fit this unit lists every part linked to that machine with bin, on hand and the reorder flag.", "Nothing listed? The part exists but is not linked — open the part and add the unit under Fits units. Or add the part (step 2)."] },
        { num: 2, title: "Add a part", who: "Shop / supervisor", items: ["Office ▸ Operations ▸ Equipment ▸ Parts (/admin/operations/equipment/parts) → Add part.", "Fill Part Name (what we call it), Part number exactly as printed, Category, Brand if known, and any Cross-reference numbers (the OEM number a NGK plug replaces, for example).", "Under What it fits pick the equipment types (Mower, Blower, Hedge Trimmer, ATV, Pole Saw…) and the specific units you know it fits.", "Under Stock set Bin location, On hand and Reorder minimum. Under Purchasing pick the Vendor (a Supplier) and enter Last price and Date priced.", "Save. If BOS answers Part number … is already used by …, open that part instead and edit it."] },
        { num: 3, title: "Receive stock", who: "Shop / office", items: ["Open the part. Raise On hand by the quantity received, set Last price and Date priced from the receipt, and set Vendor if it changed. Save.", "Put the stock in the bin shown on the record; if the bin moved, change Bin location."] },
        { num: 4, title: "Use a part on a repair", who: "Shop / mechanic", items: ["On the maintenance event for the machine, add the part under Parts Used.", "Open the part and lower On hand by what you used. Save. (A quantity on the event that lowers stock automatically is a planned addition; until then this is a by-hand step.)", "When inspecting a defect and you already know the repair, add the parts under the defect's Parts Needed so the office can order ahead."] },
        { num: 5, title: "Reorder", who: "Office", items: ["Parts list → Reorder list tab. It shows every active part with On Hand at or below its Reorder Minimum, with vendor and last price.", "Order from the vendor shown. When it arrives, receive it (step 3) — the part drops off the Reorder list on save."] },
        { num: 6, title: "Retire a part", who: "Supervisor / office", items: ["Open the part, untick Active (stocked), add a line in Notes (superseded by …, machine sold, …). Save.", "Retired parts leave the Reorder list and the default parts list; Active: Any in the filters shows them again."] }
      ]),
      spacer(160, 0),
      block("Shop parts are not Materials", "Materials are what we sell and install; every estimate and work order materials picker reads from them. A spark plug added as a Material would offer itself on customer jobs. Parts live only in the Parts list."),
      spacer(80, 0),
      warn("Stock is counted by hand for now", "Parts Used on a maintenance event records what was used; it does not yet lower On Hand. Change On Hand yourself when you pull stock, or the Reorder list is wrong."),
      spacer(80, 0),
      info("Part numbers are matched loosely", "BOS treats \"cmr6h\", \"CMR6H\" and \"CMR6H \" as the same number and refuses the duplicate. Spaces inside a STIHL number (4237 120 1800) are kept as typed."),
      spacer(160, 0),
      p([run('Quality Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("Every part on the shelf can be found by its number in the Parts list."),
      bullet("The Reorder list contains only parts that genuinely need ordering — no part shows because its minimum was left at a guess."),
      bullet("Every unit on the shop floor shows its common consumables under Parts that fit this unit."),
      bullet("On Hand on the record matches the bin on a spot check."),
      p([run('Completion', { bold: true, color: C.greenDk, size: 24 })], { before: 120, after: 60 }),
      bullet("Receiving: record updated the same day and stock in the bin shown."),
      bullet("Repair: Parts Used on the event and On Hand lowered."),
      bullet("Order placed: nothing to record until it arrives; then receive it."),
      spacer(160, 0),
      dodonts([["Add a part as a Material \"so it has a price\"", "Add it in the Parts list — price lives there too"], ["Set every minimum to 2 to be safe", "Set a minimum only where running out stops a machine; leave 0 where you don't care"], ["Pull a filter and fix the count \"later\"", "Lower On Hand while the box is open"], ["Delete a duplicate", "Retire it with a note, or merge the details into the original"]]),
      spacer(200, 0),

      sectionHdr('6  Tools & Resources'),
      bullet("Office ▸ Operations ▸ Equipment ▸ Parts — All parts / Reorder list / Add part"),
      bullet("Equipment record — Parts that fit this unit (bottom of the page)"),
      bullet("Equipment defect — Parts Needed; maintenance event — Parts Used"),
      bullet("Suppliers list — the vendors a part can be bought from"),
      bullet("Each part's own page, e.g. /equipment/parts/cmr6h"),
      spacer(200, 0),

      sectionHdr('7  Key Performance Indicators'),
      kpiTable([{ indicator: "Parts on the shelf with a BOS record", target: "100% on the shop count", owner: "Shop" }, { indicator: "Receipts entered the same day stock arrives", target: "100%", owner: "Shop / Office" }, { indicator: "Reorder list items ordered within one week of flagging", target: "100%", owner: "Office" }, { indicator: "Machines down waiting on a part that was below minimum and not ordered", target: "0 per quarter", owner: "Supervisor" }, { indicator: "Duplicate records found on the quarterly count", target: "0", owner: "Shop" }]),
      spacer(200, 0),

      sectionHdr('8  Related SOPs'),
      bullet("MNT-FLT-001 — Fleet maintenance"),
      bullet("GOV-SOP-001 — SOP Creation & Management"),
      spacer(200, 80), divider(), spacer(80, 0),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 0 }, children: [run("Brookstone Outdoors LLC  ·  MNT-PRT-001 v1.0  ·  November 2026  ·  Internal Use Only", { color: '9E9E9E', size: 18, italic: true })] }),
    ],
  }],
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync(__dirname + '/MNT-PRT-001_v1.0.docx', buf);
  console.log('Done MNT-PRT-001');
});
