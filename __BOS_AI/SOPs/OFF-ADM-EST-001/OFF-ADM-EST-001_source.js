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
      { id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { size: 36, bold: true, color: C.greenDk, font: 'Arial' },
        paragraph: { spacing: { before: 360, after: 160 }, outlineLevel: 0 } },
      { id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true,
        run: { size: 26, bold: true, color: C.green, font: 'Arial' },
        paragraph: { spacing: { before: 240, after: 100 }, outlineLevel: 1 } },
    ],
  },
  sections: [{
    properties: { page: { size: { width: 12240, height: 15840 }, margin: { top: 1080, right: 1080, bottom: 1080, left: 1080 } } },
    headers: { default: new Header({ children: [new Paragraph({
      spacing: { before: 0, after: 80 },
      border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 } },
      children: [
        run('BROOKSTONE OUTDOORS  ', { bold: true, color: C.green, size: 18 }),
        run('|  OFF-EST-001  |  Estimate Request Intake & Pipeline', { color: '757575', size: 18 }),
      ],
    })]}) },
    footers: { default: new Footer({ children: [new Paragraph({
      spacing: { before: 80, after: 0 },
      border: { top: { style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 } },
      tabStops: [{ type: TabStopType.RIGHT, position: TabStopPosition.MAX }],
      children: [
        run('OFF-EST-001 v1.0  ·  April 2026  ·  Internal Use Only  ', { color: '9E9E9E', size: 18 }),
        run('\tOffice Administration SOP', { color: '9E9E9E', size: 18 }),
      ],
    })]}) },
    children: [

      // ── COVER ────────────────────────────────────────────────────────────
      spacer(0, 480),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 40 },
        children: [run('BROOKSTONE OUTDOORS', { bold: true, color: C.green, size: 52 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 160 },
        children: [run('Creating and Maintaining Your Outdoor Spaces', { italic: true, color: '757575', size: 24 })] }),
      divider(),
      spacer(120, 80),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 60 },
        children: [run('STANDARD OPERATING PROCEDURE', { bold: true, color: C.greenDk, size: 36 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 240 },
        children: [run('OFF-EST-001 — Estimate Request Intake & Pipeline', { bold: true, color: C.gray, size: 28 })] }),
      new Table({ width: { size: 5040, type: WidthType.DXA }, columnWidths: [2000, 3040], rows: [
        new TableRow({ children: [cell(p('SOP Code',     { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p('OFF-EST-001',        { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p('Bundle',       { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p('office_administration', { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p('Version',      { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p('1.0',               { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p('Effective',    { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p('April 2026',        { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p('Applies To',   { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p('Office Staff',       { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p('Related SOP',  { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p('OFF-EST-002 (Estimator)', { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p('Governance',   { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p('GOV-SOP-001',        { size: 20, after: 0 }), { w: 3040 })] }),
      ]}),
      spacer(240, 0),
      divider(),
      spacer(200, 0),

      // ── SECTION 1: PURPOSE ───────────────────────────────────────────────
      sectionHdr('1  Purpose'),
      info(
        'Why This SOP Exists',
        'This SOP controls how office staff receive, record, and advance customer estimate requests through the BOS pipeline. It exists to eliminate lost requests, inconsistent data entry, and missed follow-ups that result in lost revenue.'
      ),
      spacer(200, 0),

      // ── SECTION 2: SCOPE ────────────────────────────────────────────────
      sectionHdr('2  Scope'),
      new Table({ width: { size: 9360, type: WidthType.DXA }, columnWidths: [4680, 4680], rows: [
        new TableRow({ children: [
          cell(p('✓  Applies To', { bold: true, color: C.green, size: 22, after: 0 }), { w: 4680, fill: C.greenLt, bc: C.green }),
          cell(p('✕  Does Not Apply To', { bold: true, color: C.red, size: 22, after: 0 }), { w: 4680, fill: C.redLt, bc: C.red }),
        ]}),
        new TableRow({ children: [
          cell(['All inbound customer estimate requests', 'Office staff and site assistants', 'All service types (maintenance and design-build)', 'BOS Estimate Board at /admin/office/estimates'].map(t => bullet(t)), { w: 4680, bc: C.green }),
          cell(['Estimator field work (see OFF-EST-002)', 'Contract creation and work order generation', 'Existing customer contract renewals (separate workflow)', 'Requests received and resolved in the same call'].map(t => bullet(t)), { w: 4680, bc: C.red }),
        ]}),
      ]}),
      spacer(200, 0),

      // ── SECTION 3: RULES & RESPONSIBILITIES ─────────────────────────────
      sectionHdr('3  Rules & Responsibilities'),
      new Table({ width: { size: 9360, type: WidthType.DXA }, columnWidths: [360, 9000], rows: [
        new TableRow({ children: [
          cell(p('MUST', { bold: true, color: C.white, size: 18, align: AlignmentType.CENTER, after: 0 }), { w: 360, fill: C.green, bc: C.green, vAlign: 'center' }),
          cell([
            bullet('Office staff must enter every estimate request into BOS the same day it is received'),
            bullet('Office staff must populate field_client_requested with specific service details — not generic descriptions'),
            bullet('Office staff must advance the request status on the board when the pipeline stage changes'),
            bullet('Office staff must check the Needs Follow-Up section every business day'),
            bullet('Office staff must create estimate sheet(s) in BOS and assign an estimator before moving to Ready to Estimate'),
            bullet('Office staff must record the customer outcome (Accepted or Declined) in BOS — not on paper'),
          ], { w: 9000 }),
        ]}),
        new TableRow({ children: [
          cell(p('MUST NOT', { bold: true, color: C.white, size: 18, align: AlignmentType.CENTER, after: 0 }), { w: 360, fill: C.red, bc: C.red, vAlign: 'center' }),
          cell([
            bullet('Office staff must not use paper notes as a substitute for BOS entry'),
            bullet('Office staff must not advance an estimate to Ready to Estimate without creating the estimate sheet and assigning an estimator'),
            bullet('Office staff must not manually check "Yes" on a contract section without a corresponding accepted estimate'),
            bullet('Office staff must not leave requests in the Needs Follow-Up section without action'),
            bullet('Office staff must not record acceptance or decline outside BOS'),
          ], { w: 9000 }),
        ]}),
      ]}),
      spacer(200, 0),

      // ── SECTION 4: PREREQUISITES ────────────────────────────────────────
      sectionHdr('4  Prerequisites'),
      bullet('BOS account with office_administration or site_assistant role'),
      bullet('Access to the Estimate Board at /admin/office/estimates'),
      bullet('Customer-provided: name, phone number, property address, service(s) requested'),
      bullet('Familiarity with OFF-EST-002 (Estimator SOP) — must understand the estimator handoff'),
      spacer(200, 0),

      // ── SECTION 5: STEPS & PROCEDURES ───────────────────────────────────
      sectionHdr('5  Steps & Procedures'),

      p('The Pipeline at a Glance', { heading: HeadingLevel.HEADING_2, before: 100 }),
      pipelineRow([
        { label: 'New — Gathering Info', desc: 'Request received. Office collects full customer details and service specifics.' },
        { label: 'Ready to Estimate',   desc: 'All info collected. Estimate sheet created in BOS. Estimator assigned and notified.' },
        { label: 'Estimating',           desc: 'Estimator owns the job — site visit scheduled, numbers being calculated.' },
        { label: 'Send Estimate',        desc: 'Estimate complete and ready to go to the customer. Action required.' },
        { label: 'Waiting on Customer',  desc: 'Proposal sent. Office follows up until customer responds.' },
      ]),
      spacer(160, 0),

      p('Pre-Checks', { heading: HeadingLevel.HEADING_2, before: 100 }),
      bullet('Confirm you are logged into BOS'),
      bullet('Confirm the Estimate Board is accessible at /admin/office/estimates'),
      bullet('Confirm you have the customer\'s name, phone, address, and service request before proceeding'),
      spacer(120, 0),

      p('Step-by-Step Procedures', { heading: HeadingLevel.HEADING_2, before: 100 }),
      stepTable([
        { num: 1, title: 'Create the Estimate Request', who: 'Office',
          items: [
            'Go to the Estimate Board and click the + New Request tab',
            'Enter the customer\'s full name, phone number, and property address',
            'Check all applicable services the customer requested',
            'In "Client Requested the Following" — describe exactly what the customer said. Include specifics: which areas, what materials, any known constraints',
            'Assign a Coordinator if known',
            'Click Save — BOS auto-matches the property and customer records if they exist',
            'Confirm the request appears in the New — Gathering Info swimlane on the board',
          ],
        },
        { num: 2, title: 'Advance to Ready to Estimate', who: 'Office',
          items: [
            'Verify: customer contact info complete, property address confirmed, services checked, Client Requested field describes the work',
            'Open the estimate request and create one estimate sheet per service requested (e.g. separate estimates for Mowing and Pre-emergent)',
            'Assign the correct estimator to each estimate using the Estimator field',
            'BOS sends an automatic email to the estimator on assignment',
            'Copy the estimate request URL and send it to the estimator (text or print) per their preference',
            'On the board row, click "Ready to Estimate →" — request moves to Ready to Estimate swimlane',
          ],
        },
        { num: 3, title: 'Monitor the Estimating Stage', who: 'Office',
          items: [
            'Request sits in the Estimating swimlane while the estimator works it',
            'Check Needs Follow-Up section daily — requests stalled in Estimating > 7 days will surface here',
            'If the estimator needs to be followed up, contact them directly — do not change the board status on their behalf',
          ],
        },
        { num: 4, title: 'Advance to Send Estimate then Waiting on Customer', who: 'Office',
          items: [
            'When the estimator confirms the estimate is ready: verify the estimate total is populated and Scope Summary is updated (not placeholder text)',
            'Send the proposal to the customer by email or in person',
            'On the board, click "Send Estimate →" then immediately click "Waiting on Customer →"',
            'Do not leave the request sitting in Send Estimate — advance both steps the same day the proposal goes out',
          ],
        },
        { num: 5, title: 'Follow Up and Record the Outcome', who: 'Office',
          items: [
            'Check Needs Follow-Up daily — requests in Waiting on Customer > 7 days will surface here',
            'Call or email the customer to check on the proposal',
            [run('Customer accepts → ', { bold: true }), run('See Step 6')],
            [run('Customer declines → ', { bold: true }), run('Click ✕ on the board row. Confirm the dialog. Request moves to Declined tab')],
            [run('Seasonal hold → ', { bold: true }), run('Click ⏸ button. Enter the resume date. Request moves to On Hold section')],
            [run('Needs more time → ', { bold: true }), run('Leave in Waiting on Customer. It resurfaces in Needs Follow-Up automatically')],
          ],
        },
        { num: 6, title: 'Record Acceptance', who: 'Office',
          items: [
            [run('Maintenance services (mowing, spraying, etc.) → ', { bold: true }), run('Mark the estimate Accepted. BOS automatically updates the linked contract section to "Accepted / Price Confirmed" and fills in the accepted price. Review the contract section to confirm.')],
            [run('Design-build (landscaping, sprinkler install) → ', { bold: true }), run('Collect the deposit. On the estimate, check Contract Signed and Deposit Received and enter the deposit amount. BOS creates the Work Order automatically.')],
            'Advance the estimate request to Accepted on the board using the action buttons',
          ],
        },
      ]),
      spacer(160, 80),

      warn('Needs Follow-Up Section', 'Check this section every morning. It surfaces requests that are stalled in any pipeline stage past the follow-up threshold. A request in this list is an at-risk opportunity — take action the same day it appears.'),
      spacer(120, 80),
      block('BOS Is the System', 'Paper notes written during a call must not substitute for BOS entry. A request that exists only on paper does not exist in BOS and will be lost. Enter it during the call or immediately after hanging up.'),
      spacer(160, 0),

      p('Quality Checks', { heading: HeadingLevel.HEADING_2, before: 100 }),
      bullet('Every inbound estimate call results in a BOS estimate request created the same day'),
      bullet('Every estimate request in Ready to Estimate has an estimate sheet created and an estimator assigned'),
      bullet('No requests sit in Send Estimate beyond the same business day'),
      bullet('No accepted estimates result in a manually checked "Yes" on the contract section — BOS does this automatically'),
      spacer(120, 0),

      p('Completion', { heading: HeadingLevel.HEADING_2, before: 100 }),
      bullet('Estimate request is in Accepted or Declined status on the board'),
      bullet('For accepted maintenance services: contract section updated to "Accepted / Price Confirmed" — verify this in BOS'),
      bullet('For accepted design-build: Work Order exists and is linked to the estimate'),
      bullet('Board shows correct status for every active request at end of each business day'),
      spacer(160, 0),

      p('Common Mistakes', { heading: HeadingLevel.HEADING_2, before: 100 }),
      dodonts([
        ['Write customer info on paper and enter it later', 'Enter the request into BOS during or immediately after the call'],
        ['Leave "Client Requested" blank or vague ("wants landscaping")', 'Describe exactly what the customer said — areas, materials, scope'],
        ['Move to Ready to Estimate without assigning an estimator', 'Always assign the estimator and create estimate sheets first'],
        ['Leave requests in Send Estimate for multiple days', 'Send the proposal and advance to Waiting on Customer the same day'],
        ['Manually check "Yes" on contract sections after acceptance', 'Accept the estimate — BOS updates the contract section automatically'],
      ]),
      spacer(200, 0),

      // ── SECTION 6: KPIs ─────────────────────────────────────────────────
      sectionHdr('6  Key Performance Indicators'),
      kpiTable([
        { indicator: 'Same-day BOS entry rate',           target: '100% of calls entered same day',       owner: 'Office' },
        { indicator: 'Needs Follow-Up items actioned',    target: '100% actioned within 1 business day',  owner: 'Office' },
        { indicator: 'Time in New — Gathering Info',      target: '< 2 business days before advancing',   owner: 'Office' },
        { indicator: 'Time in Send Estimate',             target: '< 1 business day (send and advance same day)', owner: 'Office' },
        { indicator: 'Contract section auto-update rate', target: '100% — no manual "Yes" entries',       owner: 'Office / BOS' },
        { indicator: 'Estimate request data completeness',target: 'field_client_requested populated on 100% of requests', owner: 'Office' },
      ]),
      spacer(200, 0),

      // ── SECTION 7: RELATED SOPs ──────────────────────────────────────────
      sectionHdr('7  Related SOPs'),
      bullet('OFF-EST-002 — Estimate Stage Management (Estimator) — child SOP'),
      bullet('GOV-SOP-001 — SOP Authoring Standard — governance'),
      spacer(200, 80),
      divider(),
      spacer(80, 0),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 0 },
        children: [run('Brookstone Outdoors LLC  ·  OFF-EST-001 v1.0  ·  April 2026  ·  Internal Use Only', { color: '9E9E9E', size: 18, italic: true })] }),
    ],
  }],
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync(__dirname + '/OFF-ADM-EST-001_v1.0.docx', buf);
  console.log('Done');
});
