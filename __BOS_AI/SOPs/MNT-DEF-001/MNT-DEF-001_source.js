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
// DOCUMENT — MNT-DEF-001 (Equipment Defects: Report a Problem with a Machine, Triage, Repair, Close) · generated 2026-11-17 by Code at Todd's
// request; the entity content lives in MNT-DEF-001_fields.json (same source).
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
      children: [run('BROOKSTONE OUTDOORS  ', { bold: true, color: C.green, size: 18 }), run("|  MNT-DEF-001  |  Equipment Defects: Report a Problem with a Machine, Triage, Repair, Close", { color: '757575', size: 18 })],
    })]}) },
    footers: { default: new Footer({ children: [new Paragraph({
      spacing: { before: 80, after: 0 },
      border: { top: { style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 } },
      tabStops: [{ type: TabStopType.RIGHT, position: TabStopPosition.MAX }],
      children: [run("MNT-DEF-001 v1.0  ·  November 2026  ·  Internal Use Only  ", { color: '9E9E9E', size: 18 }), run("\tMaintenance SOP", { color: '9E9E9E', size: 18 })],
    })]}) },
    children: [
      spacer(0, 480),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 40 }, children: [run('BROOKSTONE OUTDOORS', { bold: true, color: C.green, size: 52 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 160 }, children: [run('Creating and Maintaining Your Outdoor Spaces', { italic: true, color: '757575', size: 24 })] }),
      divider(), spacer(120, 80),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 60 }, children: [run('STANDARD OPERATING PROCEDURE', { bold: true, color: C.greenDk, size: 36 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 240 }, children: [run("MNT-DEF-001 — Equipment Defects: Report a Problem with a Machine, Triage, Repair, Close", { bold: true, color: C.gray, size: 28 })] }),
      new Table({ width: { size: 5040, type: WidthType.DXA }, columnWidths: [2000, 3040], rows: [
        new TableRow({ children: [cell(p("SOP Code", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("MNT-DEF-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Bundle", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("maintenance", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Version", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("1.0", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Effective", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("November 2026 (Draft until approved)", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Applies To", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("Every teammate (report); supervisors / shop (triage); office (repair record, closing)", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Related SOPs", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("MNT-FLT-001, MNT-PRT-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Governance", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("GOV-SOP-001 · GOV-SOP-002 · GOV-SOP-003", { size: 20, after: 0 }), { w: 3040 })] })
      ]}),
      spacer(240, 0), divider(), spacer(200, 0),

      sectionHdr('1  Purpose'),
      info("Why This SOP Exists", "A problem with a machine — a leak, a dead tail light, a bent blade, a hitch that will not latch — is only a problem BOS can act on once it is written on the machine itself. A defect record is what tells the shop what to fix, the office what parts to order, and the supervisor which machine must not go out. Until 2026-11-17 only two administrators could enter one, so problems lived in texts and memory; now every teammate can report from the machine's own page, with photos, the day they find it. The defect stays open until the repair is logged as a maintenance event and verified — that history is how we decide fix-versus-replace (MNT-FLT-001)."),
      spacer(200, 0),

      sectionHdr('2  Scope'),
      p([run('Applies to', { bold: true, color: C.green })], { after: 60 }),
      bullet("Every Brookstone-owned machine with an equipment record: trucks, trailers, mowers, sprayers, heavy equipment, power tools, snow plows, attachments"),
      bullet("Every teammate who finds a problem — in use, at the start of the day, loading the trailer, in the shop"),
      bullet("Supervisors and the shop, who set severity, status, parts and who is on it; the office, which logs the repair and closes the defect"),
      p([run('Does not apply to', { bold: true, color: C.red })], { before: 120, after: 60 }),
      bullet("Rented equipment on a job — that goes on the work order's Equipment section and the rental return"),
      bullet("Routine service (oil changes, scheduled maintenance) — a maintenance event under MNT-FLT-001, not a defect"),
      bullet("Shop parts stock — MNT-PRT-001"),
      bullet("Damage to a customer's property or a problem with the job — a work order note, not an equipment defect"),
      spacer(200, 0),

      sectionHdr('3  Rules & Responsibilities'),
      p([run('MUST', { bold: true, color: C.white, size: 20 })], { after: 60, border: { left: { style: BorderStyle.SINGLE, size: 24, color: C.green, space: 6 } } }),
      bullet("Whoever finds a problem must report it in BOS the same day, from the machine's page: Report a problem with this machine. One defect per problem. The machine is filled in for you."),
      bullet("The Summary must say what is wrong and where, in one line (\"Left trailer tail light out\", \"Hydraulic hose weeping at the boom\"). What you saw, when it started, what you tried and any part numbers go in Defect Notes."),
      bullet("Severity must be the one you would act on: Monitor (works, keep an eye on it) · Repair Soon (works, fix this week) · Urgent (fix before it goes out again) · Out of Service (do not use). If you would not drive it, it is Out of Service."),
      bullet("A machine that must not be used must be taken out of service in two places: tick Out of Service Required on the defect and press Change Equipment Status → Needing Repairs on the machine's Status tab, with the reason. Then tell your supervisor in person or by phone — BOS sends nobody a message."),
      bullet("Defect Photos must be attached whenever the problem can be seen — the leak, the crack, the tread, the broken bracket. General pictures of the machine stay on the equipment record."),
      bullet("Before reporting, check the machine's Defects list: if the same problem is already Open, Scheduled, In Repair or Deferred, add to that record (a dated line in the notes, more photos) instead of opening a second one. If it has come back after a repair, say so — the supervisor ticks Repeat Issue."),
      bullet("The supervisor or shop must triage every new defect within one working day: confirm Category and Severity, set Status to Scheduled once the repair has a plan, set Assigned To, and list Parts Needed so the office can order ahead (MNT-PRT-001)."),
      bullet("The office must close a defect only through its repair: a maintenance event on the machine with Related Defect set moves the defect to In Repair and copies the cost; ticking Verified Complete on that event moves it to Resolved and stamps Resolved On. That is the one way a defect closes."),
      p([run('MUST NOT', { bold: true, color: C.red, size: 20 })], { before: 160, after: 60 }),
      bullet("Do not report a problem in a text, on the whiteboard or in a work order note and stop there. It is not a defect until it is on the machine in BOS."),
      bullet("Do not set Status to Resolved by hand. The form lets anyone do it; the office's verified maintenance event is what closes a defect, and a hand-set Resolved loses the repair cost and the history."),
      bullet("Do not lower someone else's Severity, or set a defect Deferred, to make the board look better. Severity and Status are the supervisor's and the shop's to change, and Deferred needs the reason in the notes."),
      bullet("Do not put a machine back to work because its defect reads Resolved. The machine's own Status has to be set back to Active by the shop with a status update."),
      bullet("Do not open a fresh defect for the same problem each day. Add to the open one."),
      bullet("Do not enter a problem BOS already entered: an approved vehicle or trailer inspection creates its own defects. Add notes and photos to the one it made."),
      spacer(200, 0),

      sectionHdr('4  Prerequisites'),
      bullet("BOS login — every teammate can report and edit a defect; supervisors and the shop triage; the office (site admin) logs the maintenance event"),
      bullet("The machine's page in BOS — office: Office ▸ Operations ▸ Equipment → the unit; crew: a bookmarked link to the units you run (Teammates ▸ Equipment lists types only, today)"),
      bullet("A phone camera for the photos"),
      bullet("For the office: the repair invoice or the shop's record of the work (MNT-FLT-001)"),
      spacer(200, 0),

      sectionHdr('5  Steps & Procedures'),
      p([run('Overview', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      pipelineRow([{ label: "Find", desc: "Machine page → Defects: is it already listed?" }, { label: "Report", desc: "Report a problem with this machine → summary, category, severity, notes, photos → Save." }, { label: "Park it", desc: "If it must not be used: Out of Service Required + Change Equipment Status → Needing Repairs; tell the supervisor." }, { label: "Triage", desc: "Supervisor / shop: severity confirmed, Scheduled, Assigned To, Parts Needed." }, { label: "Repair & close", desc: "Office: maintenance event with Related Defect → In Repair; Verified Complete → Resolved; machine back to Active." }]),
      spacer(160, 0),
      p([run('Pre-Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("You are on the right machine's page — check the name or unit number at the top."),
      bullet("The Defects list on that page does not already show this problem open."),
      bullet("You can say what is wrong in one line and you know whether the machine is safe to use."),
      spacer(120, 0),
      p([run('Steps', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      stepTable([
        { num: 1, title: "Open the machine's page and read its Defects", who: "Every teammate", items: ["Open the machine's page in BOS. Office and supervisors: Office ▸ Operations ▸ Equipment, find the unit. Crew: use the machine's page link — bookmark the units your crew runs or ask the office for the link (the Teammates ▸ Equipment menu lists the equipment types only, not the machines, today). The page shows the machine's current Status at the top.", "Scroll to Defects. Every defect ever reported on this machine is listed newest first — date, category, severity, summary, status. No defects recorded. means none.", "Same problem already there and not Resolved? Go to step 4 instead of reporting it again."] },
        { num: 2, title: "Report the problem", who: "Every teammate", items: ["Press Report a problem with this machine (above the Defects list). Equipment is already filled in — leave it.", "Fill Date Reported (today), Defect Category (the closest: Engine, Brakes, Tire / Wheel, Lights / Electrical, Leak, Hydraulic, Blades / Deck, Spray System, Trailer, Safety, Body, Other…), Defect Severity, and the one-line Defect Summary.", "In Defect Notes / Repair Detail write what you saw, when it started, what you tried, any part numbers. Add Defect Photos of the problem itself.", "Leave the rest alone unless you are the supervisor or shop: Source Inspection, Status (stays Open), Repeat Issue, Parts Needed, the costs, Assigned To, Resolution Notes, Resolved On are triage and closing fields.", "Save. You land back on the machine's page and the defect is in the list, named machine - category - date automatically."] },
        { num: 3, title: "Take the machine out of service", who: "Reporter, then supervisor", items: ["If the machine must not be used, open the defect, tick Out of Service Required, save.", "On the machine's page open the Status tab → Change Equipment Status. Status: Needing Repairs; Reason for change: the defect summary. Save. This is the record of who parked it and why.", "Tell your supervisor or crew lead directly, the same day. BOS does not send an email or text when a defect is created."] },
        { num: 4, title: "Add to an existing defect", who: "Every teammate", items: ["Open the defect from the machine's Defects list, then its Edit tab.", "Add a dated line at the end of Defect Notes (\"11/17 — still leaking after the hose clamp, see photo\") and any new Defect Photos. Save.", "If the problem is back after a repair, tell the supervisor — they tick Repeat Issue and raise the severity if it is worse."] },
        { num: 5, title: "Triage", who: "Supervisor / shop, daily", items: ["Work from Office ▸ Operations ▸ Equipment ▸ Open Defects (every defect not yet Resolved, worst severity first) or from the machine's page.", "Open each new defect: confirm Category and Severity; set Status to Scheduled once the repair has a plan or date; set Assigned To (who is fixing it or arranging the vendor); add Parts Needed from the parts list so the office orders before the machine is on the bench (MNT-PRT-001); enter Estimated Repair Cost when known.", "Deferred is allowed for a problem we are choosing to live with — write why in the notes and keep the severity honest.", "For anything Out of Service, check the machine's Status reads Needing Repairs; if not, change it (step 3)."] },
        { num: 6, title: "Log the repair and close the defect", who: "Office (site admin) — per MNT-FLT-001", items: ["When the work is done or the invoice arrives, add a Maintenance Event on the machine: Equipment, Related Defect = this defect, Event Date, Event Type, Vendor or Mechanic, Work Performed, Parts / Labor / Total cost, Parts Used. On save BOS moves the defect to In Repair and copies the total into Actual Repair Cost.", "When the repair is confirmed good, edit the event and tick Verified Complete (and Verified By). BOS sets the defect Resolved with Resolved On = the event date. Add Resolution Notes on the defect if the fix needs explaining.", "Set the machine back: Change Equipment Status → Active (use Being Serviced while it sits at a shop).", "A report entered by mistake (wrong machine, not a defect) is closed by the office only: fix the Equipment field if it was the wrong unit, otherwise write \"Entered in error — why\" in Resolution Notes and set it Resolved. That is the one hand-set Resolved allowed."] }
      ]),
      spacer(160, 0),
      block("A new report does not change the machine's status by itself", "Ticking Out of Service Required on a brand-new defect records the need, but BOS sets the machine to Needing Repairs only when that box is ticked on a defect that already exists. Use Change Equipment Status on the machine every time it must not go out — that is also the record of who parked it and why."),
      spacer(80, 0),
      warn("Nobody is notified", "BOS sends no email or text when a defect is created or changed. The Open Defects board is the office's list, and it is checked daily — a machine that must not be used is told to the supervisor in person the same day."),
      spacer(80, 0),
      info("Inspections create defects on their own", "When an approved vehicle or trailer inspection records an unsafe item — brake fluid low, a tire damaged, a light out, a hitch issue — BOS creates the defect itself, or ticks Repeat Issue and raises the severity on an open one of the same kind from the last 30 days. Do not enter those again; add your notes and photos to the one BOS made."),
      spacer(160, 0),
      p([run('Quality Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("Every defect names one machine, one problem, a severity the reporter would act on, and a date — nothing lives only in a text."),
      bullet("Every machine with Out of Service Required ticked shows Needing Repairs (or Being Serviced) as its Status until the shop sets it back."),
      bullet("Every Open defect older than a working day has been triaged: Category and Severity confirmed, Status Scheduled or Deferred with a reason, Assigned To set."),
      bullet("Every Resolved defect has a linked, verified maintenance event — none set Resolved by hand except an \"Entered in error\" closed by the office."),
      bullet("Photos show the problem, not the whole truck."),
      p([run('Completion', { bold: true, color: C.greenDk, size: 24 })], { before: 120, after: 60 }),
      bullet("Reporter: the defect is on the machine's Defects list, and the supervisor has been told if the machine is out of service."),
      bullet("Supervisor / shop: Severity confirmed, Status, Assigned To and Parts Needed set."),
      bullet("Office: maintenance event verified complete → defect Resolved; machine Status back to Active."),
      spacer(160, 0),
      dodonts([["Text the foreman \"truck 2 brakes grinding\" and leave it there", "Report it on Truck 2's page before the next job — then text the foreman"], ["Write \"noise\" as the summary", "\"Grinding from front right wheel under braking, started today\""], ["Pick Monitor so nobody blames you for parking the machine", "Pick the severity you would act on; Out of Service if you would not drive it"], ["Set the defect Resolved because the shop said it is fixed", "Leave it — the office's verified maintenance event closes it and keeps the cost"], ["Open a new defect for the same leak every morning", "Add a dated line and a photo to the open one"], ["Photograph the whole truck", "Photograph the leak, the crack, the tread"]]),
      spacer(200, 0),

      sectionHdr('6  Tools & Resources'),
      bullet("Machine page in BOS (office: Office ▸ Operations ▸ Equipment → the unit; crew: the machine page link): Defects list + Report a problem with this machine; Status tab → Change Equipment Status"),
      bullet("Each defect's own page, /equipment_defect/<id> — Edit tab for notes and photos"),
      bullet("Open Defects board: Office ▸ Operations ▸ Equipment ▸ Open Defects (/admin/operations/equipment/open-defects) — supervisors and office"),
      bullet("Maintenance event on the machine (office) — Related Defect links it to the defect (MNT-FLT-001)"),
      bullet("Parts list and Parts Needed / Parts Used (MNT-PRT-001)"),
      bullet("Inspection review queue: /admin/operations/equipment/inspection-review (office) — approved inspections create defects"),
      spacer(200, 0),

      sectionHdr('7  Key Performance Indicators'),
      kpiTable([{ indicator: "Problems reported in BOS the day they are found", target: "100% — none first heard of at the shop", owner: "Supervisor" }, { indicator: "New defects triaged within one working day", target: "100%", owner: "Supervisor / Shop" }, { indicator: "Machines with Out of Service Required ticked whose Status is still Active", target: "0 on the daily Open Defects check", owner: "Supervisor" }, { indicator: "Resolved defects without a verified maintenance event", target: "0", owner: "Office" }, { indicator: "Repeat issues within 30 days of a resolved repair", target: "Reviewed monthly; trending down", owner: "Shop" }, { indicator: "Defects with at least one photo where the problem is visible", target: "90% or better", owner: "Supervisor" }]),
      spacer(200, 0),

      sectionHdr('8  Related SOPs'),
      bullet("MNT-FLT-001 — Logging Vehicle & Equipment Repairs in BOS"),
      bullet("MNT-PRT-001 — Shop Parts and Consumables: Add, Receive, Reorder, Retire"),
      bullet("GOV-SOP-001 — SOP Creation & Management"),
      spacer(200, 80), divider(), spacer(80, 0),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 0 }, children: [run("Brookstone Outdoors LLC  ·  MNT-DEF-001 v1.0  ·  November 2026  ·  Internal Use Only", { color: '9E9E9E', size: 18, italic: true })] }),
    ],
  }],
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync(__dirname + '/MNT-DEF-001_v1.0.docx', buf);
  console.log('Done MNT-DEF-001');
});
