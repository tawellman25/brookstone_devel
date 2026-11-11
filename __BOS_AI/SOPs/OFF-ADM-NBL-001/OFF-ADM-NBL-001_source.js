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
// DOCUMENT — OFF-ADM-NBL-001 (Non-Billable Time: Review and Correction) · generated 2026-11-11 by Code at Todd's
// request; the entity content lives in OFF-ADM-NBL-001_fields.json (same source).
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
      children: [run('BROOKSTONE OUTDOORS  ', { bold: true, color: C.green, size: 18 }), run("|  OFF-ADM-NBL-001  |  Non-Billable Time: Review and Correction", { color: '757575', size: 18 })],
    })]}) },
    footers: { default: new Footer({ children: [new Paragraph({
      spacing: { before: 80, after: 0 },
      border: { top: { style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 } },
      tabStops: [{ type: TabStopType.RIGHT, position: TabStopPosition.MAX }],
      children: [run("OFF-ADM-NBL-001 v1.0  ·  November 2026  ·  Internal Use Only  ", { color: '9E9E9E', size: 18 }), run("\tOffice Administration SOP", { color: '9E9E9E', size: 18 })],
    })]}) },
    children: [
      spacer(0, 480),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 40 }, children: [run('BROOKSTONE OUTDOORS', { bold: true, color: C.green, size: 52 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 160 }, children: [run('Creating and Maintaining Your Outdoor Spaces', { italic: true, color: '757575', size: 24 })] }),
      divider(), spacer(120, 80),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 60 }, children: [run('STANDARD OPERATING PROCEDURE', { bold: true, color: C.greenDk, size: 36 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 240 }, children: [run("OFF-ADM-NBL-001 — Non-Billable Time: Review and Correction", { bold: true, color: C.gray, size: 28 })] }),
      new Table({ width: { size: 5040, type: WidthType.DXA }, columnWidths: [2000, 3040], rows: [
        new TableRow({ children: [cell(p("SOP Code", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("OFF-ADM-NBL-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Bundle", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("office_administration", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Version", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("1.0", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Effective", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("November 2026 (Draft until approved)", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Applies To", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("Office staff who bill; supervisors", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Related SOPs", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("WEB-SYS-SGN-001, OFF-ADM-TGT-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Governance", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("GOV-SOP-001 · GOV-SOP-002 · GOV-SOP-003", { size: 20, after: 0 }), { w: 3040 })] })
      ]}),
      spacer(240, 0), divider(), spacer(200, 0),

      sectionHdr('1  Purpose'),
      info("Why This SOP Exists", "Non-billable time is hours a teammate worked on a billable job that the crew lead decided not to charge — an extra man, a customer on a set budget. BOS keeps those hours on the teammate's record and leaves them off Total Time, so the labor on the invoice is right without office cleanup. This SOP covers how the office reads those calls before invoicing, how a supervisor reviews them for patterns, and the one correct way to change a call — so no invoice goes out with the wrong hours and no hour is given away unseen."),
      spacer(200, 0),

      sectionHdr('2  Scope'),
      p([run('Applies to', { bold: true, color: C.green })], { after: 60 }),
      bullet("Office staff who invoice work orders from the billing views"),
      bullet("Supervisors who review crew hours and the Unbilled Hours report"),
      bullet("Any correction of a Non-billable flag or reason on a time entry"),
      bullet("All work order types that bill labor by the hour"),
      p([run('Does not apply to', { bold: true, color: C.red })], { before: 120, after: 60 }),
      bullet("The crew lead's call at sign-off — see WEB-SYS-SGN-001"),
      bullet("Overhead work (shop, dump runs, equipment) — separate procedure to follow"),
      bullet("Pay and compensable hours — a non-billable hour is still a paid hour"),
      bullet("Pricing, rates and billing adjustments for anything other than hours"),
      spacer(200, 0),

      sectionHdr('3  Rules & Responsibilities'),
      p([run('MUST', { bold: true, color: C.white, size: 20 })], { after: 60, border: { left: { style: BorderStyle.SINGLE, size: 24, color: C.green, space: 6 } } }),
      bullet("Before invoicing, office must read the Non-billable column on the billing view and, where it is not empty, the Hours cards on the work order. Total Time is the billable hours; Non-billable Time is the rest."),
      bullet("Office must invoice from Total Time and Labor as BOS shows them — the non-billable hours are already out of both."),
      bullet("A correction must be made on the time entry (click the hour card → Edit Time Entry) while the work order is Complete: tick or untick Non-billable, pick the reason, Save. BOS recomputes Total Time, Labor and the work order total."),
      bullet("If the work order is already Invoiced or Paid, office must first move it back to Complete with a status update, make the correction, then re-invoice — the same path as a material correction. BOS refuses the change otherwise."),
      bullet("Every correction must carry a reason. The office person's name and the time are stamped automatically and shown on the card."),
      bullet("Supervisors must review the Unbilled Hours block on the Teammate Variance page weekly — by reason and by teammate — and raise patterns with the crew lead."),
      bullet("Office must tell the crew's supervisor before changing a crew lead's call."),
      p([run('MUST NOT', { bold: true, color: C.red, size: 20 })], { before: 160, after: 60 }),
      bullet("Office must not use Billing Adjustment to take a man's hours off an invoice. It carries dollars, not hours — the unbilled time disappears from every report and nobody can see where the hours went."),
      bullet("Office must not edit hours or flags on an Invoiced or Paid work order in place. Move it back to Complete first; the change is tracked."),
      bullet("Office must not remove a crew lead's Non-billable call without the supervisor knowing — it was made on site by the person who saw the job."),
      bullet("Nobody may hand-edit the \"set by\" and \"set at\" stamps; they are written by BOS and are the record of who decided."),
      bullet("Crew members cannot change the flag — only supervisor and office roles see the checkbox on the time entry form. Do not ask a crew member to do it."),
      spacer(200, 0),

      sectionHdr('4  Prerequisites'),
      bullet("BOS account with the administration or supervisor role"),
      bullet("The billing views (Office › Work Orders › Billing) and the work order page Hours section"),
      bullet("The Teammate Variance page: /admin/office/operations/teammates/variance"),
      bullet("Familiarity with WEB-SYS-SGN-001 — what the crew lead sees and decides"),
      spacer(200, 0),

      sectionHdr('5  Steps & Procedures'),
      p([run('Overview', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      pipelineRow([{ label: "Read", desc: "Non-billable column on the billing view; cards on the WO." }, { label: "Judge", desc: "Call looks right → invoice as shown." }, { label: "Correct", desc: "Edit Time Entry while Complete; reason required." }, { label: "Review", desc: "Weekly Unbilled Hours by reason and teammate." }]),
      spacer(160, 0),
      p([run('Pre-Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("The work order is Complete — not In Progress (the crew has not finished) and not yet Invoiced."),
      bullet("You know what the customer agreed (budget, set price) from the contract, estimate or notes."),
      spacer(120, 0),
      p([run('Steps', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      stepTable([
        { num: 1, title: "Read the billing view", who: "Office", items: ["Open the billing view you normally invoice from. The Non-billable column sits right after Total Time and is blank when nothing was marked.", "A value there means a crew lead made a call on that job — open the work order before invoicing."] },
        { num: 2, title: "Read the work order", who: "Office", items: ["In the Hours section each time entry is a card. A non-billable card is grey with a Non-billable badge, the reason, who set it and when; its hours are struck through.", "The Total line reads \"X hrs · Y hrs non-billable\". Total Time on the work order equals the billable hours; Non-billable Time equals Y."] },
        { num: 3, title: "Invoice as shown — when the call is right", who: "Office", items: ["Labor was computed on the billable hours at completion. Invoice normally; nothing to adjust.", "Mention the non-billable hours in Billing Notes only if the customer was promised a credit in writing."] },
        { num: 4, title: "Correct — when the call is wrong", who: "Office / Supervisor", items: ["Tell the crew's supervisor what you are changing and why.", "Click the hour card → Edit Time Entry. Tick or untick Non-billable, pick the Non-billable reason, Save.", "Back on the work order, confirm Total Time, Non-billable Time, Labor and the work order total changed as expected. BOS recomputes on save."] },
        { num: 5, title: "Correct an invoiced job", who: "Office", items: ["Add a status update moving the work order back to Complete.", "Make the correction as in step 4.", "Re-invoice, and note the reason for the re-issue in Billing Notes."] },
        { num: 6, title: "Weekly review", who: "Supervisor", items: ["Open the Teammate Variance page for the week. The Unbilled hours block shows the total, the split by reason and the top teammates; the table has a Non-billable column per person.", "Open a teammate's detail page to see the NB badge on individual entries.", "Raise patterns with the crew lead: the same man marked daily, \"Other\" used often, or budget jobs never marked."] }
      ]),
      spacer(160, 0),
      block("Billing Adjustment is not for hours", "In 2026 the office used Billing Adjustment on 201 work orders, 57 of them negative. Those adjustments carry no hours, so nobody can see which man's time was given away or why. Use the Non-billable flag; it keeps the hours and the reason."),
      spacer(80, 0),
      warn("Invoiced means locked", "BOS refuses any change to a time entry whose work order is Invoiced or Paid. Moving the work order back to Complete first is deliberate: the change is tracked, and the invoice is re-issued on purpose, not drifted."),
      spacer(160, 0),
      p([run('Quality Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("Total Time + Non-billable Time equals the hours on the cards for every work order you invoice."),
      bullet("Labor on the invoice matches Total Time × the rate — no manual recalculation."),
      bullet("No work order this week carries a Billing Adjustment that should have been a Non-billable flag."),
      bullet("Every correction shows a reason and a name on the card."),
      p([run('Completion', { bold: true, color: C.greenDk, size: 24 })], { before: 120, after: 60 }),
      bullet("Invoice the work order as usual."),
      bullet("When a correction changed the invoice, record what changed in Billing Notes."),
      bullet("Supervisor: note patterns raised with crew leads; they feed the monthly review of unbilled hours."),
      spacer(160, 0),
      dodonts([["Knock dollars off with Billing Adjustment because \"we had too many guys\"", "Have the extra man's time marked Non-billable · Over-staffed so the hours are kept and visible"], ["Edit the time entry on an invoiced work order", "Move the work order back to Complete, correct, re-invoice"], ["Quietly undo a crew lead's call", "Tell the supervisor first; the call was made on site"], ["Ask the crew lead to fix it from his phone next week", "Correct it yourself while the job is Complete — the office and supervisors hold the checkbox"]]),
      spacer(200, 0),

      sectionHdr('6  Tools & Resources'),
      bullet("Billing views — Office › Work Orders › Billing (Non-billable column after Total Time)"),
      bullet("Work order page — Hours section cards; Edit Time Entry (modal)"),
      bullet("Work order status updates — to move an invoiced job back to Complete"),
      bullet("Teammate Variance page — Unbilled hours block and Non-billable column; teammate detail page"),
      bullet("Billing Notes on the work order"),
      spacer(200, 0),

      sectionHdr('7  Key Performance Indicators'),
      kpiTable([{ indicator: "Corrections carrying a reason and a stamp", target: "100% (BOS enforces)", owner: "Office" }, { indicator: "Billing Adjustments used for hours", target: "0 per month", owner: "Office" }, { indicator: "Invoiced work orders re-opened for hour corrections", target: "Fewer than 2 per month", owner: "Office" }, { indicator: "Unbilled Hours report reviewed", target: "Weekly, by reason and by teammate", owner: "Supervisor" }, { indicator: "Unbilled hours as a share of clocked hours", target: "Trending down; reasons explain the rest", owner: "Supervisor / Owner" }]),
      spacer(200, 0),

      sectionHdr('8  Related SOPs'),
      bullet("WEB-SYS-SGN-001 — Sign-Off: Confirm Crew Hours and Decide Who Gets Billed"),
      bullet("OFF-ADM-TGT-001 — Target Hours on Cleanup Work Orders"),
      bullet("GOV-SOP-001 — SOP Creation & Management"),
      spacer(200, 80), divider(), spacer(80, 0),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 0 }, children: [run("Brookstone Outdoors LLC  ·  OFF-ADM-NBL-001 v1.0  ·  November 2026  ·  Internal Use Only", { color: '9E9E9E', size: 18, italic: true })] }),
    ],
  }],
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync(__dirname + '/OFF-ADM-NBL-001_v1.0.docx', buf);
  console.log('Done OFF-ADM-NBL-001');
});
