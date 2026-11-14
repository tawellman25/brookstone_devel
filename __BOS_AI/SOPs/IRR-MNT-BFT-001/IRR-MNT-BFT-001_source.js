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
// DOCUMENT — IRR-MNT-BFT-001 (Backflow Device Field Test: Enter the Test, Sign Off, Tag the Device) · generated 2026-11-13 by Code at Todd's
// request; the entity content lives in IRR-MNT-BFT-001_fields.json (same source).
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
      children: [run('BROOKSTONE OUTDOORS  ', { bold: true, color: C.green, size: 18 }), run("|  IRR-MNT-BFT-001  |  Backflow Device Field Test: Enter the Test, Sign Off, Tag the Device", { color: '757575', size: 18 })],
    })]}) },
    footers: { default: new Footer({ children: [new Paragraph({
      spacing: { before: 80, after: 0 },
      border: { top: { style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 } },
      tabStops: [{ type: TabStopType.RIGHT, position: TabStopPosition.MAX }],
      children: [run("IRR-MNT-BFT-001 v1.0  ·  November 2026  ·  Internal Use Only  ", { color: '9E9E9E', size: 18 }), run("\tSprinkler Maintenance SOP", { color: '9E9E9E', size: 18 })],
    })]}) },
    children: [
      spacer(0, 480),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 40 }, children: [run('BROOKSTONE OUTDOORS', { bold: true, color: C.green, size: 52 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 160 }, children: [run('Creating and Maintaining Your Outdoor Spaces', { italic: true, color: '757575', size: 24 })] }),
      divider(), spacer(120, 80),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 60 }, children: [run('STANDARD OPERATING PROCEDURE', { bold: true, color: C.greenDk, size: 36 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 240 }, children: [run("IRR-MNT-BFT-001 — Backflow Device Field Test: Enter the Test, Sign Off, Tag the Device", { bold: true, color: C.gray, size: 28 })] }),
      new Table({ width: { size: 5040, type: WidthType.DXA }, columnWidths: [2000, 3040], rows: [
        new TableRow({ children: [cell(p("SOP Code", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("IRR-MNT-BFT-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Bundle", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("sprinkler_maintenance", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Version", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("1.0", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Effective", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("November 2026 (Draft until approved)", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Applies To", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("Irrigation techs (certified backflow testers); office for records", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Related SOPs", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("WEB-SYS-SGN-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Governance", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("GOV-SOP-001 · GOV-SOP-002 · GOV-SOP-003", { size: 20, after: 0 }), { w: 3040 })] })
      ]}),
      spacer(240, 0), divider(), spacer(200, 0),

      sectionHdr('1  Purpose'),
      info("Why This SOP Exists", "A backflow test is a regulated record. The readings, the tester's certification, the gauge and the result have to be kept per device, and the customer and the water district need a report and a tag. In BOS each device at a property has its own record, each visit is a Backflow Testing work order, and each device tested is a test entered on that work order. When the crew signs the work order off, BOS updates the device (last test, next due, status), writes a frozen report PDF per test and makes the service tag printable. This SOP exists so every test is on the right device, entered once, with the readings and tester the regulation expects — and so nothing is recorded as a pass that was not."),
      spacer(200, 0),

      sectionHdr('2  Scope'),
      p([run('Applies to', { bold: true, color: C.green })], { after: 60 }),
      bullet("Every backflow test performed by Brookstone, on every testable device type: RP, DCVA, PVB, SVB"),
      bullet("Backflow Testing work orders and the test records under them"),
      bullet("The device records on the property, the report PDF and the HB25-1077 service tag"),
      p([run('Does not apply to', { bold: true, color: C.red })], { before: 120, after: 60 }),
      bullet("Repairs beyond the test — recorded under Repairs Recommended and done as a repair job"),
      bullet("Non-testable devices: AVB, dual check (DuC) and hose-bibb vacuum breakers (HBVB) have no test cocks and are never \"tested\""),
      bullet("Setting a device to Repaired, Out of Service or Replaced — by hand only, office or supervisor"),
      bullet("Water-district filing — not built in BOS"),
      spacer(200, 0),

      sectionHdr('3  Rules & Responsibilities'),
      p([run('MUST', { bold: true, color: C.white, size: 20 })], { after: 60, border: { left: { style: BorderStyle.SINGLE, size: 24, color: C.green, space: 6 } } }),
      bullet("The tester must hold a current backflow certification and use a gauge with a current calibration. BOS warns, citing Reg 11.39, when either is missing or expired; the warning does not block the save — the tester does."),
      bullet("Every test must be entered against the device actually tested. Check the Device ID (BF-…) on the record against the tag on the assembly before saving."),
      bullet("Every test must record Test Date, Pass / Fail, the readings for that device type, and photos of the tag — a close-up and one showing it on the assembly."),
      bullet("A FAIL must be recorded as a FAIL, with the repair needed under Repairs Recommended. A repaired-and-retested device gets a second test on the same work order; the latest test date decides the device's result."),
      bullet("The tester must be the person who performed the test. Left blank it becomes whoever saves the form; the office entering a test for a tech must set Tester to the tech."),
      bullet("The work order must be signed off through the sign-off form the same day. Device update, report PDF and tag only happen when the work order reaches Complete."),
      bullet("A device not yet in BOS must be created from the test form (Create new device for this property) before the test is saved — never as a second record for an existing device."),
      p([run('MUST NOT', { bold: true, color: C.red, size: 20 })], { before: 160, after: 60 }),
      bullet("Do not test or enter a test on an AVB, dual check or hose-bibb vacuum breaker. BOS does not stop you; the device type does."),
      bullet("Do not tick Warranty on the sign-off for a backflow test. A warranty sign-off sets the work order to Warrantied, not Complete, and the device is never updated and no report is produced."),
      bullet("Do not change readings after sign-off to \"clean up\" a report. The stored PDF is the issued report and does not change; the device record would no longer match it."),
      bullet("Do not delete or replace a device record because the assembly was swapped. Mark the old one Replaced, set Replaced By, and create the new one."),
      spacer(200, 0),

      sectionHdr('4  Prerequisites'),
      bullet("Current backflow tester certification on your teammate profile (BOS snapshots the certificate number onto the test)"),
      bullet("Your assigned test gauge with calibration on record"),
      bullet("A Backflow Testing work order for the property, or the device page for the device"),
      bullet("The customer's water on and the device accessible"),
      spacer(200, 0),

      sectionHdr('5  Steps & Procedures'),
      p([run('Overview', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      pipelineRow([{ label: "Find", desc: "Backflow Testing WO → Backflow Tests group; or the property's Devices section." }, { label: "Test", desc: "Enter Test Results → device, readings, pass/fail, tag photos." }, { label: "Fail?", desc: "Repairs Recommended; repair and retest on the same WO." }, { label: "Sign off", desc: "Sign-off form → Complete. Device updates; PDF written." }, { label: "Tag", desc: "Device page → Test History → Print Tag." }]),
      spacer(160, 0),
      p([run('Pre-Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("The work order is for this property and this visit."),
      bullet("The Device ID on the record matches the tag on the assembly, or the device is new and about to be created."),
      bullet("The device type is testable (RP, DCVA, PVB, SVB)."),
      bullet("Your certification and gauge calibration are current."),
      spacer(120, 0),
      p([run('Steps', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      stepTable([
        { num: 1, title: "Open the work order and the device", who: "Tech", items: ["Open the Backflow Testing work order. Existing tests show as cards in the Backflow Tests group; the property page's Devices section shows every device with its status, last test and next due (\"Overdue\", \"Due soon\" within 60 days, \"No test on record\").", "From a device page, Enter Test Results creates a new Backflow Testing work order (Open) with the test already linked to that device — use it when there is no work order yet."] },
        { num: 2, title: "Start the test record", who: "Tech", items: ["On the work order press the green Enter Test Results button below the test cards. BOS adds an empty test, copies the device from the most recent test on this work order, and opens the form: Added a backflow test. Enter the test results below.", "Check Backflow Device. Change it if this test is for another device on the property. If the device is not in BOS yet, press Create new device for this property, enter Device type, Serial number and Physical location, then Save device."] },
        { num: 3, title: "Enter the test", who: "Tech", items: ["Test Details: Test Date (defaults to now once Pass/Fail is set), Tester (you — pre-selected), Certification Number (filled from your credential on save; leave it), Initial Test if this is the device's first test, Pass / Fail, Test Gauge (defaults to your assigned gauge).", "Test Readings — only the readings for the device type show, each with a photo slot: RP — Line Pressure (psi), Check Valve 1 (psid), Check Valve 2 (psid), Relief Valve (psid) · DCVA — Line Pressure, Check Valve 1, Check Valve 2 · PVB / SVB — Line Pressure, Air Inlet (psid), Check Valve (psid). If the readings shown do not match the device, reload the form after fixing the device.", "Tag Photos: a close-up of the tag and one showing it on the assembly.", "Repairs & Report: write what the device needs under Repairs Recommended. Report PDF fills itself at sign-off. Leave the Office Admin section alone.", "Save. A warning about gauge calibration or your certification means stop and resolve it before signing off — it will not stop the save."] },
        { num: 4, title: "Repair and retest, if it failed", who: "Tech", items: ["Record the FAIL as it is. If you repair on site, press Enter Test Results again on the same work order for the after-repair test. BOS uses the latest test date for the device's result and status.", "If the repair is a separate job, leave the FAIL recorded and tell the office; the device shows in the dashboard's failed bucket until a passing test is entered."] },
        { num: 5, title: "Sign off", who: "Crew lead / tech", items: ["Complete the sign-off form for the work order (irrigation crew sign-off) the same day. Leave Warranty unticked. When the work order reaches Complete, BOS, per device: on PASS sets status Active, Last Test Date, Last Pass Date, Next Due Date (test date + test frequency, 12 months by default), and Initial Test Date on a first passing initial test; on FAIL sets status Failed and Last Test Date, leaving Last Pass and Next Due as they were.", "One report PDF per test is written at sign-off and linked under the test card. It is never regenerated."] },
        { num: 6, title: "Tag the device", who: "Tech / office", items: ["Device ID → device page → Test History → Print Tag. The HB25-1077 Backflow Service Tag carries Device ID, type, test date, pass/fail and a QR to the device's current record. Print it as often as needed; it is generated from the record each time.", "The customer's copy of the report is the PDF under the test card (View / Download)."] }
      ]),
      spacer(160, 0),
      block("Warranty sign-off skips everything", "Ticking Warranty on the sign-off sets the work order to Warrantied instead of Complete. The device is not updated, no PDF is written, no tag reflects the test. Never on a backflow test."),
      spacer(80, 0),
      warn("Non-testable devices will still accept a test", "AVB, dual check and hose-bibb vacuum breakers have no test cocks. BOS hides their next-due fields and keeps them off the compliance dashboard, but it will not stop a test being entered against one — all six readings simply appear. Do not."),
      spacer(80, 0),
      info("Certificate number and gauge", "The certificate number on a test is your credential as of the test date, filled once if the field is empty and never overwritten; it locks when the work order is Complete. The gauge defaults to the one assigned to you — fix it if you used another."),
      spacer(160, 0),
      p([run('Quality Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("Each device tested has one test on the work order with the right Device ID, readings for its type, Pass/Fail and two tag photos."),
      bullet("After sign-off the device card shows the new last-test date and the next-due date a year out (on a pass)."),
      bullet("A report PDF is linked under every test card."),
      bullet("No warning about certification or gauge was left standing at sign-off."),
      p([run('Completion', { bold: true, color: C.greenDk, size: 24 })], { before: 120, after: 60 }),
      bullet("Work order Complete; device updated; PDF issued; tag printed and on the assembly."),
      bullet("Failed devices reported to the office with the repair needed."),
      spacer(160, 0),
      dodonts([["Enter one test and \"cover\" two devices in the notes", "One test per device, each on its own Device ID"], ["Tick Warranty because the device is under our install warranty", "Leave Warranty unticked on a test; note the warranty in the repair job instead"], ["Fix a reading after sign-off so the PDF \"looks right\"", "The issued PDF stands; enter a new test if the device was retested"], ["Create a second device record because you cannot find the first", "Search the property's Devices section by Device ID or serial first"]]),
      spacer(200, 0),

      sectionHdr('6  Tools & Resources'),
      bullet("Backflow Testing work order — Backflow Tests group, Enter Test Results button"),
      bullet("Property page — Devices section (status, last test, next due); device page — Test History, Print Tag"),
      bullet("Compliance dashboard — /admin/operations/backflow (office; overdue / due soon / failed)"),
      bullet("Your teammate profile — certification and assigned gauge"),
      bullet("HB25-1077 service tag — /backflow/tag/{test} via Print Tag"),
      spacer(200, 0),

      sectionHdr('7  Key Performance Indicators'),
      kpiTable([{ indicator: "Tests with readings matching the device type and two tag photos", target: "100%", owner: "Tech" }, { indicator: "Backflow work orders signed off the day of the test (Warranty unticked)", target: "100%", owner: "Crew lead" }, { indicator: "Tests saved with a certification or calibration warning left standing", target: "0", owner: "Tech / Supervisor" }, { indicator: "Devices with \"No test on record\" that were visited this season", target: "0", owner: "Office" }, { indicator: "Report PDF present per test after sign-off", target: "100%", owner: "Office" }]),
      spacer(200, 0),

      sectionHdr('8  Related SOPs'),
      bullet("WEB-SYS-SGN-001 — Sign-Off: Confirm Crew Hours and Decide Who Gets Billed"),
      bullet("GOV-SOP-001 — SOP Creation & Management"),
      spacer(200, 80), divider(), spacer(80, 0),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 0 }, children: [run("Brookstone Outdoors LLC  ·  IRR-MNT-BFT-001 v1.0  ·  November 2026  ·  Internal Use Only", { color: '9E9E9E', size: 18, italic: true })] }),
    ],
  }],
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync(__dirname + '/IRR-MNT-BFT-001_v1.0.docx', buf);
  console.log('Done IRR-MNT-BFT-001');
});
