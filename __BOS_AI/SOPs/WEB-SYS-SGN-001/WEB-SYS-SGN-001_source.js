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
// DOCUMENT — WEB-SYS-SGN-001 (Sign-Off: Confirm Crew Hours and Decide Who Gets Billed) · generated 2026-11-11 by Code at Todd's
// request; the entity content lives in WEB-SYS-SGN-001_fields.json (same source).
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
      children: [run('BROOKSTONE OUTDOORS  ', { bold: true, color: C.green, size: 18 }), run("|  WEB-SYS-SGN-001  |  Sign-Off: Confirm Crew Hours and Decide Who Gets Billed", { color: '757575', size: 18 })],
    })]}) },
    footers: { default: new Footer({ children: [new Paragraph({
      spacing: { before: 80, after: 0 },
      border: { top: { style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 } },
      tabStops: [{ type: TabStopType.RIGHT, position: TabStopPosition.MAX }],
      children: [run("WEB-SYS-SGN-001 v1.0  ·  November 2026  ·  Internal Use Only  ", { color: '9E9E9E', size: 18 }), run("\tSystem Procedures SOP", { color: '9E9E9E', size: 18 })],
    })]}) },
    children: [
      spacer(0, 480),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 40 }, children: [run('BROOKSTONE OUTDOORS', { bold: true, color: C.green, size: 52 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 160 }, children: [run('Creating and Maintaining Your Outdoor Spaces', { italic: true, color: '757575', size: 24 })] }),
      divider(), spacer(120, 80),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 60 }, children: [run('STANDARD OPERATING PROCEDURE', { bold: true, color: C.greenDk, size: 36 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 240 }, children: [run("WEB-SYS-SGN-001 — Sign-Off: Confirm Crew Hours and Decide Who Gets Billed", { bold: true, color: C.gray, size: 28 })] }),
      new Table({ width: { size: 5040, type: WidthType.DXA }, columnWidths: [2000, 3040], rows: [
        new TableRow({ children: [cell(p("SOP Code", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("WEB-SYS-SGN-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Bundle", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("system_procedures", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Version", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("1.0", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Effective", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("November 2026 (Draft until approved)", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Applies To", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("Crew leads / foremen (all crews)", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Related SOPs", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("OFF-ADM-NBL-001, OFF-ADM-TGT-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Governance", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("GOV-SOP-001 · GOV-SOP-002 · GOV-SOP-003", { size: 20, after: 0 }), { w: 3040 })] })
      ]}),
      spacer(240, 0), divider(), spacer(200, 0),

      sectionHdr('1  Purpose'),
      info("Why This SOP Exists", "The sign-off form is where a crew lead closes a job in BOS. It confirms every crew member's hours for that work order and decides whose time is charged to the customer. This SOP exists so the hours we bill match the people the job actually needed, decided by the one person who knows — the crew lead, at the moment the work ends — and recorded under that person's name. Hours marked non-billable still count as hours worked for the teammate; only the invoice leaves them out."),
      spacer(200, 0),

      sectionHdr('2  Scope'),
      p([run('Applies to', { bold: true, color: C.green })], { after: 60 }),
      bullet("Every crew lead or foreman who saves a sign-off form in BOS (Clean-Up, Landscape, Irrigation, Spray and Fertilizing crew sign-offs, and the Mowing tasks form)"),
      bullet("Every crew member's time on that work order, whether they clocked in themselves, were clocked in by the crew clock, or are added through the roster at sign-off"),
      bullet("All services and all work order types that bill labor by the hour"),
      p([run('Does not apply to', { bold: true, color: C.red })], { before: 120, after: 60 }),
      bullet("Office corrections after the work order is Complete — see OFF-ADM-NBL-001"),
      bullet("Setting hourly rates, trip fees or prices — business settings, office only"),
      bullet("Invoicing — the office invoices from the billable hours this form produces"),
      bullet("Shop time, dump runs and other overhead work (separate procedure to follow)"),
      spacer(200, 0),

      sectionHdr('3  Rules & Responsibilities'),
      p([run('MUST', { bold: true, color: C.white, size: 20 })], { after: 60, border: { left: { style: BorderStyle.SINGLE, size: 24, color: C.green, space: 6 } } }),
      bullet("The crew lead must save the sign-off the same day the work ends — on site or before leaving the truck."),
      bullet("The crew lead must tick every person who worked on site in the crew roster (Who was on site with this truck / Those on Crew) before saving. A person left off the roster has no hours on the job."),
      bullet("The crew lead must confirm or correct every end time BOS lists under Confirm end times for forgotten clock-outs. The prefilled time is an estimate, not a record."),
      bullet("The crew lead must leave everyone billed unless that person's time should not be charged to the customer: an extra man the job did not need (Over-staffed), or a customer who agreed a set price or budget (Customer budget)."),
      bullet("The crew lead must pick a reason for every Non-billable tick: Over-staffed, Customer budget, Training, Rework or Other. BOS refuses the save without one."),
      bullet("The crew lead must make the call for the men on site at the time the work was performed — not later from memory, and not for someone else's crew."),
      bullet("If a call was wrong, the crew lead must tell the supervisor or office the same day so it can be corrected while the work order is still Complete."),
      p([run('MUST NOT', { bold: true, color: C.red, size: 20 })], { before: 160, after: 60 }),
      bullet("The crew lead must not mark time non-billable to hide a slow day, a late start or a mistake on the crew's side. Rework is a reason; a slow morning is not."),
      bullet("The crew lead must not sign off while anyone is still clocked in on another job without checking — BOS blocks the save and names them for a reason."),
      bullet("The crew lead must not leave Who gets billed untouched on a job where the customer agreed a budget. Unbilled hours that are never marked cannot be seen by anyone and are lost."),
      bullet("The crew lead must not discuss billing, hours or price with the customer. The office invoices."),
      bullet("The crew lead must not change a Non-billable call after the work order is invoiced — it is locked. Report it to the office instead."),
      spacer(200, 0),

      sectionHdr('4  Prerequisites'),
      bullet("BOS teammate account with sign-off access for your crew type"),
      bullet("Every crew member clocked in on the work order, or ready to be added through the roster at sign-off"),
      bullet("Knowledge of what the customer agreed (set budget, extra men) — from the supervisor or office before the job"),
      bullet("Familiarity with the clock buttons and the Hours section on the work order page"),
      spacer(200, 0),

      sectionHdr('5  Steps & Procedures'),
      p([run('Overview', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      pipelineRow([{ label: "Roster", desc: "Tick everyone who was on site today." }, { label: "End times", desc: "Confirm or fix each forgotten clock-out." }, { label: "Who gets billed", desc: "Untick only the men the customer should not pay for; pick the reason." }, { label: "Save", desc: "BOS completes the job and sums the billable hours." }]),
      spacer(160, 0),
      p([run('Pre-Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("The work is finished, or the job is being closed for the day with the supervisor's agreement."),
      bullet("Everyone on the crew has clocked out, or will be closed by the roster at sign-off."),
      bullet("You know whether this customer agreed a set price or budget."),
      spacer(120, 0),
      p([run('Steps', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      stepTable([
        { num: 1, title: "Open the sign-off form", who: "Crew lead", items: ["On the work order page scroll to the Sign-Off section and open the form for your crew type.", "The form shows the work order, the roster field and the man-hours block."] },
        { num: 2, title: "Fill the roster", who: "Crew lead", items: ["Tick every person who worked on site today — including anyone borrowed from another crew.", "On complex jobs (sprinkler, landscape) click Refresh man hours after changing the roster so the blocks below update."] },
        { num: 3, title: "Confirm end times", who: "Crew lead", items: ["Under Confirm end times for forgotten clock-outs, enter the time each listed person actually finished. The prefilled value is an estimate.", "People on the roster with no clock entry get one created when you save, using the crew's times — tell the office if theirs was different."] },
        { num: 4, title: "Read the target line (cleanups)", who: "Crew lead", items: ["Fall and spring cleanups show Target X hrs · Clocked so far Y hrs. It is information for the supervisor review — never a reason to change anyone's hours."] },
        { num: 5, title: "Decide who gets billed", who: "Crew lead", items: ["In Who gets billed, every person starts as billed. Leave it that way unless the customer should not pay for that person's time.", "For each person whose time is not charged: tick Non-billable and pick the reason — Over-staffed · Customer budget · Training · Rework · Other.", "The hours stay on the job and on the teammate's record. Only the invoice leaves them out. Your name and the time are recorded with the call."] },
        { num: 6, title: "Finish and save", who: "Crew lead", items: ["Complete the rest of the form (trucks taken, notes, photos as your crew's form requires) and Save.", "BOS sets the work order to Complete, closes the clock entries, and sums the billable hours into Total Time; non-billable hours go to Non-billable Time."] }
      ]),
      spacer(160, 0),
      warn("A reason is required", "A Non-billable tick without a reason will not save. \"Other\" is for cases the list does not cover — not for skipping the question."),
      spacer(80, 0),
      block("Not a tool for hiding time", "Supervisors review every non-billable hour by reason and by teammate on the Unbilled Hours report. A pattern of Non-billable ticks on ordinary days will be asked about."),
      spacer(160, 0),
      p([run('Quality Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("The work order shows status Complete."),
      bullet("The Hours section shows one card per person; each non-billable card carries a grey Non-billable badge with the reason and your name."),
      bullet("The Total line under the cards reads \"X hrs non-billable\" when any time was marked."),
      bullet("Nobody who worked today is missing from the Hours section."),
      p([run('Completion', { bold: true, color: C.greenDk, size: 24 })], { before: 120, after: 60 }),
      bullet("Nothing else to do — the office sees the call on the work order and on the billing views."),
      bullet("If you made a mistake, tell the supervisor or office the same day. They can correct it while the job is Complete (OFF-ADM-NBL-001). Once invoiced it is locked."),
      spacer(160, 0),
      dodonts([["Sign off from the shop the next morning from memory", "Sign off on site, the same day, while the crew is in front of you"], ["Untick a man because the job ran long", "Untick a man only when the customer should not pay for him — and say why"], ["Leave a budget job fully billed and let the office \"sort it out\"", "Mark the extra hours Non-billable · Customer budget so the invoice is right the first time"], ["Tell the customer what they will be charged", "Leave billing to the office; the sign-off is your record, not a quote"]]),
      spacer(200, 0),

      sectionHdr('6  Tools & Resources'),
      bullet("BOS work order page — Sign-Off section (your crew type's form)"),
      bullet("Clock buttons and crew clock on the work order page (clock in / out, Clock In Crew)"),
      bullet("Hours section — one card per time entry, with the Non-billable badge once marked"),
      bullet("My Schedule — your day's jobs; cleanups show their target hours"),
      spacer(200, 0),

      sectionHdr('7  Key Performance Indicators'),
      kpiTable([{ indicator: "Sign-offs saved the same day as the work", target: "100%", owner: "Crew lead" }, { indicator: "Non-billable ticks carrying a reason", target: "100% (BOS enforces)", owner: "Crew lead" }, { indicator: "Budget jobs with the extra hours marked at sign-off", target: "100% — no billing adjustments used instead", owner: "Crew lead / Office" }, { indicator: "Non-billable hours reviewed on the Unbilled Hours report", target: "Weekly, by reason and by teammate", owner: "Supervisor" }, { indicator: "Corrections needed after sign-off", target: "Trending down month over month", owner: "Supervisor" }]),
      spacer(200, 0),

      sectionHdr('8  Related SOPs'),
      bullet("OFF-ADM-NBL-001 — Non-Billable Time: Review and Correction"),
      bullet("OFF-ADM-TGT-001 — Target Hours on Cleanup Work Orders"),
      bullet("GOV-SOP-001 — SOP Creation & Management"),
      spacer(200, 80), divider(), spacer(80, 0),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 0 }, children: [run("Brookstone Outdoors LLC  ·  WEB-SYS-SGN-001 v1.0  ·  November 2026  ·  Internal Use Only", { color: '9E9E9E', size: 18, italic: true })] }),
    ],
  }],
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync(__dirname + '/WEB-SYS-SGN-001_v1.0.docx', buf);
  console.log('Done WEB-SYS-SGN-001');
});
