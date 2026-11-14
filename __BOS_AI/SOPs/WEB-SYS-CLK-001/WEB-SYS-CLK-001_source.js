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
// DOCUMENT — WEB-SYS-CLK-001 (Clock In and Out on a Work Order: Yourself and Your Crew) · generated 2026-11-13 by Code at Todd's
// request; the entity content lives in WEB-SYS-CLK-001_fields.json (same source).
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
      children: [run('BROOKSTONE OUTDOORS  ', { bold: true, color: C.green, size: 18 }), run("|  WEB-SYS-CLK-001  |  Clock In and Out on a Work Order: Yourself and Your Crew", { color: '757575', size: 18 })],
    })]}) },
    footers: { default: new Footer({ children: [new Paragraph({
      spacing: { before: 80, after: 0 },
      border: { top: { style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 } },
      tabStops: [{ type: TabStopType.RIGHT, position: TabStopPosition.MAX }],
      children: [run("WEB-SYS-CLK-001 v1.0  ·  November 2026  ·  Internal Use Only  ", { color: '9E9E9E', size: 18 }), run("\tSystem Procedures SOP", { color: '9E9E9E', size: 18 })],
    })]}) },
    children: [
      spacer(0, 480),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 40 }, children: [run('BROOKSTONE OUTDOORS', { bold: true, color: C.green, size: 52 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 160 }, children: [run('Creating and Maintaining Your Outdoor Spaces', { italic: true, color: '757575', size: 24 })] }),
      divider(), spacer(120, 80),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 60 }, children: [run('STANDARD OPERATING PROCEDURE', { bold: true, color: C.greenDk, size: 36 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 240 }, children: [run("WEB-SYS-CLK-001 — Clock In and Out on a Work Order: Yourself and Your Crew", { bold: true, color: C.gray, size: 28 })] }),
      new Table({ width: { size: 5040, type: WidthType.DXA }, columnWidths: [2000, 3040], rows: [
        new TableRow({ children: [cell(p("SOP Code", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("WEB-SYS-CLK-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Bundle", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("system_procedures", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Version", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("1.0", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Effective", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("November 2026 (Draft until approved)", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Applies To", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("Every teammate; crew leads for the crew clock", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Related SOPs", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("WEB-SYS-SGN-001, OFF-ADM-NBL-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Governance", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("GOV-SOP-001 · GOV-SOP-002 · GOV-SOP-003", { size: 20, after: 0 }), { w: 3040 })] })
      ]}),
      spacer(240, 0), divider(), spacer(200, 0),

      sectionHdr('1  Purpose'),
      info("Why This SOP Exists", "Hours on a work order come from clock entries and nothing else — billing adds up every person's entry. The green button records your own time. The crew clock lets the crew lead clock everyone on site in and out in one press, each person getting their own entry. This SOP exists so every hour worked is on the job it was worked on, under the right name, started and ended when it actually happened. Since 2026-11-13 the crew clock also remembers the morning crew: after lunch, or at the next property, the people who worked earlier today and are clocked out are already ticked — a default to check, not a record."),
      spacer(200, 0),

      sectionHdr('2  Scope'),
      p([run('Applies to', { bold: true, color: C.green })], { after: 60 }),
      bullet("Every teammate who works on a work order, on any crew"),
      bullet("Crew leads and foremen using Clock In Crew and Clock Out Crew"),
      bullet("Lunch breaks, moving between properties during the day, late arrivals and early finishes"),
      p([run('Does not apply to', { bold: true, color: C.red })], { before: 120, after: 60 }),
      bullet("Deciding whose time is billed to the customer — WEB-SYS-SGN-001 (sign-off)"),
      bullet("Correcting another person's entry after the fact — supervisor or office, OFF-ADM-NBL-001"),
      bullet("Shop time, dump runs and other overhead work (procedure to follow)"),
      bullet("Work orders already invoiced or paid — they are locked to time entry; the office corrects those"),
      spacer(200, 0),

      sectionHdr('3  Rules & Responsibilities'),
      p([run('MUST', { bold: true, color: C.white, size: 20 })], { after: 60, border: { left: { style: BorderStyle.SINGLE, size: 24, color: C.green, space: 6 } } }),
      bullet("Every teammate must clock in when they start on the job and clock out when they stop — including for lunch. The entry is the record; memory is not."),
      bullet("Every teammate must clock in on the work order they are actually working. Open the right property before pressing the button."),
      bullet("The crew lead must tick only people who are physically on site. Clock In Crew is an attestation under your name: your location is stamped on every entry and the note records that you pressed the button."),
      bullet("The crew lead must check the pre-ticked list before confirming after lunch or at the next property: untick anyone who went home, tick anyone who arrived. BOS ticks from today's entries; it does not know who came back."),
      bullet("Anyone still clocked in on a previous job must clock out there first. BOS blocks the single button and lists the open entries; the crew clock greys the person out and skips them."),
      bullet("A long entry (over 8 hours, 14 on long-job types) must be confirmed only when it is real. If the time is wrong, fix the entry instead of confirming it."),
      bullet("Every teammate must report a wrong entry the same day to the crew lead or office, while the work order is still open."),
      p([run('MUST NOT', { bold: true, color: C.red, size: 20 })], { before: 160, after: 60 }),
      bullet("Do not clock in for someone else with your own green button. Use Clock In Crew, so BOS records it as a crew action by you."),
      bullet("Do not tick a person who has not arrived yet, or who left — \"they will be here in a minute\" is a false entry until they are."),
      bullet("Do not leave the crew clocked in through lunch to save a press. Clock out for lunch and back in after; the pre-select makes it two taps."),
      bullet("Do not use the crew clock to rebuild yesterday. Missed days are an office correction."),
      bullet("Do not clock in on an invoiced or paid work order. BOS refuses; tell the office if a correction is needed."),
      spacer(200, 0),

      sectionHdr('4  Prerequisites'),
      bullet("BOS teammate account, logged in on the phone or tablet in the truck"),
      bullet("The work order page for the property you are standing on"),
      bullet("Phone location services on (BOS records the clock-in position silently; without it the office sees no distance)"),
      bullet("Crew lead only: you know who is on site right now"),
      spacer(200, 0),

      sectionHdr('5  Steps & Procedures'),
      p([run('Overview', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      pipelineRow([{ label: "Clock In", desc: "Yourself: green button. Crew: Clock In Crew → tick → Clock In Selected." }, { label: "Work", desc: "One entry per person. Early finisher clocks out alone; late arrival clocks in alone." }, { label: "Lunch / next job", desc: "Clock Out Crew. Clock In Crew again — the morning crew is already ticked." }, { label: "Clock Out", desc: "Clock Out, or Clock Out Crew. Confirm a long entry only if real." }, { label: "Sign-off", desc: "Crew lead closes the job — WEB-SYS-SGN-001." }]),
      spacer(160, 0),
      p([run('Pre-Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("You are on the work order page for the property you are at (check the address or nickname in the title)."),
      bullet("You are not still clocked in on an earlier job today."),
      bullet("Crew lead: you can see everyone you are about to tick."),
      spacer(120, 0),
      p([run('Steps', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      stepTable([
        { num: 1, title: "Clock yourself in", who: "Every teammate", items: ["On the work order page press the green Clock In button. Your entry opens and the work order moves to In Progress for the office.", "If BOS says you are still clocked in elsewhere, it lists those entries. Clock out of each one (or set its end time), then press Clock In on this WO."] },
        { num: 2, title: "Clock the crew in", who: "Crew lead", items: ["Press the blue Clock In Crew button. The Who is on site? list shows your crew type, anyone scheduled, and anyone already clocked in.", "Tick everyone on site. Greyed rows cannot be ticked: (already clocked in) here, or (clocked in on another job) — that person clocks out there first.", "Press Clock In Selected. BOS opens one entry per person with the same start time, your location on each, and a note that you pressed the button.", "Anyone on site who is not on the list clocks in on their own with the green button."] },
        { num: 3, title: "Lunch, and moving to the next property", who: "Crew lead", items: ["Press Clock Out Crew when the crew stops. Every open entry on this job closes; someone who clocked out early keeps their own end time.", "After lunch — on the same job or the next one — press Clock In Crew. The people who worked earlier today and are now clocked out are already ticked, each with their clock-out time, e.g. (out 11:32 AM). The line above the list says how many: 3 people from earlier today are already ticked. Untick anyone who has not come back.", "Untick whoever went home. Tick a new arrival. Then Clock In Selected.", "On a new job the pre-select is your crew — the people you clocked in earlier today. On the same job it is everyone who worked here today, whoever clocked them in."] },
        { num: 4, title: "Clock out", who: "Every teammate / Crew lead", items: ["Your own time: press Clock Out. The crew: Clock Out Crew closes every open entry on the job.", "If an entry is over the limit (8 hours; 14 on long-job types) BOS names the people and the hours and asks you to confirm. Press OK only if those hours are real. Otherwise cancel and fix the start or end time on the entry."] },
        { num: 5, title: "Check the Hours section", who: "Crew lead", items: ["The Hours section on the work order shows one card per person with start and end. Everyone who worked today should have a card; nobody should have two open.", "A missing person clocks in now, or is added through the roster at sign-off (WEB-SYS-SGN-001). Your own entry you can edit; anyone else's is a supervisor or office correction."] }
      ]),
      spacer(160, 0),
      warn("Pre-ticked is a default, not a record", "BOS ticks the morning crew from today's time entries. It cannot know who went home at lunch or who was borrowed by another crew. Read the list before you press Clock In Selected — each tick is you saying that person is here."),
      spacer(80, 0),
      block("One open entry per person", "A person clocked in on another job is greyed out and skipped, even if ticked. A second open entry would bill the same hour on two work orders. Clock them out on the other job first."),
      spacer(80, 0),
      info("Your location is on every crew entry", "The crew clock stamps the crew lead's GPS position on each person's entry and records who pressed the button. It is an attestation, and it is how the office tells one crew action from five separate clock-ins."),
      spacer(160, 0),
      p([run('Quality Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("The work order shows In Progress after the first clock-in."),
      bullet("One Hours card per person on site; no card for anyone who was not."),
      bullet("After lunch, the same people are back on the clock within a minute of starting — not at the end of the day from memory."),
      bullet("No long-entry confirmation was pressed to hide a forgotten clock-out."),
      p([run('Completion', { bold: true, color: C.greenDk, size: 24 })], { before: 120, after: 60 }),
      bullet("At the end of the job the crew lead signs off (WEB-SYS-SGN-001); forgotten clock-outs are confirmed there."),
      bullet("Report a wrong entry the same day; the office or supervisor corrects it while the work order is still open (OFF-ADM-NBL-001)."),
      spacer(160, 0),
      dodonts([["Leave everyone clocked in through lunch", "Clock Out Crew at lunch, Clock In Crew after — the crew is pre-ticked"], ["Press Clock In Selected without reading the list", "Untick who went home, tick who arrived, then confirm"], ["Clock yourself in on the next property while still open on the last", "Clock Out Crew before you drive off"], ["Confirm a 13-hour entry because the dialog is in the way", "Cancel and fix the end time; confirm only real long days"]]),
      spacer(200, 0),

      sectionHdr('6  Tools & Resources'),
      bullet("Work order page — Clock In / Clock Out (your own time)"),
      bullet("Work order page — Clock In Crew / Clock Out Crew (the crew lead)"),
      bullet("Hours section on the work order — one card per person, edit your own"),
      bullet("My Schedule — today's jobs and their work order pages"),
      spacer(200, 0),

      sectionHdr('7  Key Performance Indicators'),
      kpiTable([{ indicator: "Entries closed the same day they opened", target: "100%", owner: "Crew lead" }, { indicator: "Crew clock-ins where everyone ticked was on site", target: "100% — spot-checked against GPS distance", owner: "Supervisor" }, { indicator: "People with two open entries at the same time", target: "0", owner: "Supervisor" }, { indicator: "Long-entry confirmations", target: "Reviewed weekly; each one a real long day", owner: "Supervisor" }, { indicator: "Time corrections requested after the day", target: "Trending down month over month", owner: "Office" }]),
      spacer(200, 0),

      sectionHdr('8  Related SOPs'),
      bullet("WEB-SYS-SGN-001 — Sign-Off: Confirm Crew Hours and Decide Who Gets Billed"),
      bullet("OFF-ADM-NBL-001 — Non-Billable Time: Review and Correction"),
      bullet("GOV-SOP-001 — SOP Creation & Management"),
      spacer(200, 80), divider(), spacer(80, 0),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 0 }, children: [run("Brookstone Outdoors LLC  ·  WEB-SYS-CLK-001 v1.0  ·  November 2026  ·  Internal Use Only", { color: '9E9E9E', size: 18, italic: true })] }),
    ],
  }],
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync(__dirname + '/WEB-SYS-CLK-001_v1.0.docx', buf);
  console.log('Done WEB-SYS-CLK-001');
});
