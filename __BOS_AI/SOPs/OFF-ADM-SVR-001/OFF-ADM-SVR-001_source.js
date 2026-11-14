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
// DOCUMENT — OFF-ADM-SVR-001 (Service Requests: Review the Queue and Approve into a Work Order) · generated 2026-11-13 by Code at Todd's
// request; the entity content lives in OFF-ADM-SVR-001_fields.json (same source).
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
      children: [run('BROOKSTONE OUTDOORS  ', { bold: true, color: C.green, size: 18 }), run("|  OFF-ADM-SVR-001  |  Service Requests: Review the Queue and Approve into a Work Order", { color: '757575', size: 18 })],
    })]}) },
    footers: { default: new Footer({ children: [new Paragraph({
      spacing: { before: 80, after: 0 },
      border: { top: { style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 } },
      tabStops: [{ type: TabStopType.RIGHT, position: TabStopPosition.MAX }],
      children: [run("OFF-ADM-SVR-001 v1.0  ·  November 2026  ·  Internal Use Only  ", { color: '9E9E9E', size: 18 }), run("\tOffice Administration SOP", { color: '9E9E9E', size: 18 })],
    })]}) },
    children: [
      spacer(0, 480),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 40 }, children: [run('BROOKSTONE OUTDOORS', { bold: true, color: C.green, size: 52 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 160 }, children: [run('Creating and Maintaining Your Outdoor Spaces', { italic: true, color: '757575', size: 24 })] }),
      divider(), spacer(120, 80),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 60 }, children: [run('STANDARD OPERATING PROCEDURE', { bold: true, color: C.greenDk, size: 36 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 240 }, children: [run("OFF-ADM-SVR-001 — Service Requests: Review the Queue and Approve into a Work Order", { bold: true, color: C.gray, size: 28 })] }),
      new Table({ width: { size: 5040, type: WidthType.DXA }, columnWidths: [2000, 3040], rows: [
        new TableRow({ children: [cell(p("SOP Code", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("OFF-ADM-SVR-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Bundle", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("office_administration", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Version", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("1.0", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Effective", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("November 2026 (Draft until approved)", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Applies To", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("Office administration, supervisors", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Related SOPs", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("OFF-ADM-EST-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Governance", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("GOV-SOP-001 · GOV-SOP-002 · GOV-SOP-003", { size: 20, after: 0 }), { w: 3040 })] })
      ]}),
      spacer(240, 0), divider(), spacer(200, 0),

      sectionHdr('1  Purpose'),
      info("Why This SOP Exists", "Customers ask for work through the website — the winterizing list, the fall cleanup page, the contact and holiday-lights forms — and the win-back callers create requests from the call list. Each request is a record of what the customer asked for; it is never the work itself. This SOP exists so every request is turned into a work order through the one button that does it properly: Approve & Create WO creates the work order for the matched property, links it back to the request and marks the request Converted in one step. A work order created any other way leaves the request open and unlinked, the customer appears unanswered, and the office later cannot tell which job answered which request."),
      spacer(200, 0),

      sectionHdr('2  Scope'),
      p([run('Applies to', { bold: true, color: C.green })], { after: 60 }),
      bullet("Every service request in Office ▸ Service Requests, whatever form it came from"),
      bullet("Office staff and supervisors who review, approve, mark covered, mark duplicate or reject requests"),
      bullet("Win-back callers: the call list's Create WO already runs this same conversion"),
      p([run('Does not apply to', { bold: true, color: C.red })], { before: 120, after: 60 }),
      bullet("Creating work orders for existing customers who phoned the office — those are created from the property page as before"),
      bullet("Estimates and estimate requests — OFF-ADM-EST-001"),
      bullet("Editing the public forms or their eligibility rules — system administration"),
      spacer(200, 0),

      sectionHdr('3  Rules & Responsibilities'),
      p([run('MUST', { bold: true, color: C.white, size: 20 })], { after: 60, border: { left: { style: BorderStyle.SINGLE, size: 24, color: C.green, space: 6 } } }),
      bullet("A request that should become a job must be converted with Approve & Create WO — never by creating the work order from the property page. The button creates the work order, links it to the request and marks the request Converted together."),
      bullet("The office must work the queue on its default filter — New and Needs Review — at least daily in season."),
      bullet("A request with no matched property must get one before approval: use Create Customer & Property when the person is new, or attach the existing property when the match was missed."),
      bullet("Every request the office closes without a job must be closed with the matching action: Mark Already Covered when a work order or contract already covers it, Mark Duplicate when another request is active, Reject (with the reason) otherwise. Rejection reasons are required and saved to the office notes."),
      bullet("What the customer typed must be treated as evidence, not truth: the property match, the eligibility result and the review flags on the card are what BOS verified."),
      bullet("Review flags (contract already completed for the year, supply mismatch, ZIP out of area, snow contract requested…) must be read before approving. They never block the button; they tell you what to check."),
      p([run('MUST NOT', { bold: true, color: C.red, size: 20 })], { before: 160, after: 60 }),
      bullet("Do not create the work order by hand and then try to mark the request Converted — BOS refuses: a request cannot be Converted without a linked work order."),
      bullet("Do not approve a request whose eligibility shows a reason — a non-cancelled work order already exists this season, a contract covers the service, the owner is on credit hold or do-not-schedule, or the property is marked no-services. Use Mark Already Covered or Reject."),
      bullet("Do not expect an email to go to the customer on approval or rejection. None is sent. If the customer needs an answer, the office calls or emails them."),
      bullet("Do not approve a general inquiry (contact form) as if it were a service request. It has no service, so the conversion fails; create the work order or estimate the ordinary way and reject the request with the reason."),
      spacer(200, 0),

      sectionHdr('4  Prerequisites'),
      bullet("Office administration, supervisor or site admin role (the queue needs the administer service requests permission)"),
      bullet("Office ▸ Service Requests is open"),
      bullet("For approval: the request shows a matched property and no eligibility reason"),
      spacer(200, 0),

      sectionHdr('5  Steps & Procedures'),
      p([run('Overview', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      pipelineRow([{ label: "Queue", desc: "Office ▸ Service Requests, filtered to New + Needs Review." }, { label: "Check", desc: "Matched property, eligibility, review flags, what was asked." }, { label: "Fix", desc: "Create Customer & Property, or attach the property, when needed." }, { label: "Decide", desc: "Approve & Create WO · Already Covered · Duplicate · Reject." }, { label: "Confirm", desc: "Request shows Converted with the work order linked." }]),
      spacer(160, 0),
      p([run('Pre-Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("The queue is filtered to New and Needs Review (the default when you open it)."),
      bullet("For the request in hand: you know the property, the service and the season it is for."),
      spacer(120, 0),
      p([run('Steps', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      stepTable([
        { num: 1, title: "Open the queue", who: "Office", items: ["Office ▸ Service Requests (/admin/office/service-requests). Each request is a card: name, status badge, reference (W-…, F-…), submitted date, campaign, phone, email, address, matched property, work order link, interest tags, review flags and an Actions menu.", "Filters: Type, Status, Service year, Source, Campaign, \"Review flag contains\". Tabs: Queue, Report, Campaign QRs."] },
        { num: 2, title: "Read the card", who: "Office", items: ["Matched property: present means BOS found exactly one property. Needs Review means none, more than one, or the request was not eligible — the candidates are shown to the office.", "Status set at intake: New (matched and eligible) · Already Covered (a work order or contract already covers it, linked on the card) · Duplicate (another request is active) · Needs Review.", "Review flags are warnings, not blocks: contract_completed_for_year, standing_flag_no_contract, supply_mismatch, snow_contract_requested, zip_out_of_area. Check each before deciding.", "Fall cleanup requests may have created a linked winterizing request (Needs Review) and, if the customer ticked a spring project, an estimate and a contact — look for those on the card."] },
        { num: 3, title: "Fix the match when needed", who: "Office", items: ["No property matched and the person is new: Actions ▸ Create Customer & Property. It creates the customer and fills in the property so Approve can finish. Check the address it built.", "No property matched but they are an existing customer: open the request, attach the right property, save."] },
        { num: 4, title: "Approve", who: "Office", items: ["Actions ▸ Approve & Create WO. The confirmation page repeats Submitted, Matched property, Current eligibility and Flags, and warns No property matched — assign one before converting when it cannot proceed.", "Press Create Work Order. BOS re-checks eligibility, creates the work order (status Open) for the matched property and the request's service, copies the customer's notes and access notes into the work to do, attaches the current-year residential contract, adds a system note \"Created from Service Request …\", and sets the request to Converted with the work order linked — all in one transaction.", "Success reads Work order N created and request marked Converted. Then schedule the work order as usual."] },
        { num: 5, title: "Close without a job", who: "Office", items: ["Mark Already Covered — links the existing work order or contract when BOS can find it.", "Mark Duplicate — when another request for the same thing is still active.", "Reject — enter the Reason for rejection; it is saved to the office notes as \"Rejected: …\". Call or email the customer if they need to hear it; BOS does not."] },
        { num: 6, title: "Win-back calls", who: "Win-back callers", items: ["The call list's Create WO button creates a winterizing request tagged with the reactivation campaign and converts it at once through the same conversion. Nothing further to do in the queue; the request appears there as Converted."] }
      ]),
      spacer(160, 0),
      block("Only the button links request and work order", "A work order created from the property page for a request leaves the request open with no link. Four such requests had to be relinked by hand in September; one property with two possible work orders could not be, and had to be matched manually."),
      spacer(80, 0),
      warn("Eligibility is re-checked at the moment you approve", "If a work order was created for that property in the meantime, or a contract now covers the service, the button refuses: \"No longer eligible to convert… Use Mark Already Covered / Duplicate instead.\" That is the system catching a double job, not an error."),
      spacer(80, 0),
      info("The season check looks at when a work order was created", "A request can show \"already covered\" because of a work order created this season, even if that job has no scheduled date yet. Read the linked work order before deciding."),
      spacer(160, 0),
      p([run('Quality Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("Every converted request shows status Converted with a work order link on its card."),
      bullet("No request sits at New or Needs Review for more than a working day in season."),
      bullet("Every rejected request carries a reason in the office notes."),
      bullet("No work order for a web request exists without the request linked to it."),
      p([run('Completion', { bold: true, color: C.greenDk, size: 24 })], { before: 120, after: 60 }),
      bullet("Approved: schedule the new work order; the request needs nothing more."),
      bullet("Covered / Duplicate / Rejected: the customer has been answered by phone or email where an answer was due."),
      spacer(160, 0),
      dodonts([["Create the WO from the property page because it is quicker", "Approve & Create WO — it is one click and it links everything"], ["Approve past a review flag without reading it", "Open the flagged item, then decide"], ["Reject with \"n/a\" as the reason", "Write the reason the customer would recognise"], ["Mark Converted by hand after making a WO", "BOS refuses; use the button, or ask admin to relink"]]),
      spacer(200, 0),

      sectionHdr('6  Tools & Resources'),
      bullet("Office ▸ Service Requests — Queue, Report, Campaign QRs (/admin/office/service-requests)"),
      bullet("Request card actions: Create Customer & Property · Approve & Create WO · Mark Already Covered · Mark Duplicate · Reject"),
      bullet("Win-back call list (/admin/office/winterize/win-back) — Create WO converts in place"),
      bullet("The public forms the requests come from: /winterize, the fall cleanup page, /contact, /holiday-lights"),
      spacer(200, 0),

      sectionHdr('7  Key Performance Indicators'),
      kpiTable([{ indicator: "Requests still New or Needs Review after one working day (in season)", target: "0", owner: "Office" }, { indicator: "Converted requests with a linked work order", target: "100% (BOS enforces)", owner: "Office" }, { indicator: "Work orders for web requests created outside the button", target: "0 — checked on the monthly relink audit", owner: "Supervisor" }, { indicator: "Rejections carrying a reason", target: "100% (BOS enforces)", owner: "Office" }]),
      spacer(200, 0),

      sectionHdr('8  Related SOPs'),
      bullet("OFF-ADM-EST-001 — Estimate Request Intake & Pipeline"),
      bullet("GOV-SOP-001 — SOP Creation & Management"),
      spacer(200, 80), divider(), spacer(80, 0),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 0 }, children: [run("Brookstone Outdoors LLC  ·  OFF-ADM-SVR-001 v1.0  ·  November 2026  ·  Internal Use Only", { color: '9E9E9E', size: 18, italic: true })] }),
    ],
  }],
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync(__dirname + '/OFF-ADM-SVR-001_v1.0.docx', buf);
  console.log('Done OFF-ADM-SVR-001');
});
