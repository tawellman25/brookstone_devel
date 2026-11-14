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
// DOCUMENT — OFF-ADM-REV-001 (Customer Reviews: Entering, Approving and Handwritten Notes) · generated 2026-11-13 by Code at Todd's
// request; the entity content lives in OFF-ADM-REV-001_fields.json (same source).
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
      children: [run('BROOKSTONE OUTDOORS  ', { bold: true, color: C.green, size: 18 }), run("|  OFF-ADM-REV-001  |  Customer Reviews: Entering, Approving and Handwritten Notes", { color: '757575', size: 18 })],
    })]}) },
    footers: { default: new Footer({ children: [new Paragraph({
      spacing: { before: 80, after: 0 },
      border: { top: { style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 } },
      tabStops: [{ type: TabStopType.RIGHT, position: TabStopPosition.MAX }],
      children: [run("OFF-ADM-REV-001 v1.0  ·  November 2026  ·  Internal Use Only  ", { color: '9E9E9E', size: 18 }), run("\tOffice Administration SOP", { color: '9E9E9E', size: 18 })],
    })]}) },
    children: [
      spacer(0, 480),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 40 }, children: [run('BROOKSTONE OUTDOORS', { bold: true, color: C.green, size: 52 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 160 }, children: [run('Creating and Maintaining Your Outdoor Spaces', { italic: true, color: '757575', size: 24 })] }),
      divider(), spacer(120, 80),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 60 }, children: [run('STANDARD OPERATING PROCEDURE', { bold: true, color: C.greenDk, size: 36 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 240 }, children: [run("OFF-ADM-REV-001 — Customer Reviews: Entering, Approving and Handwritten Notes", { bold: true, color: C.gray, size: 28 })] }),
      new Table({ width: { size: 5040, type: WidthType.DXA }, columnWidths: [2000, 3040], rows: [
        new TableRow({ children: [cell(p("SOP Code", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("OFF-ADM-REV-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Bundle", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("office_administration", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Version", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("1.0", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Effective", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("November 2026 (Draft until approved)", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Applies To", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("Office administration", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Related SOPs", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("SOP-WEB-002", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Governance", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("GOV-SOP-001 · GOV-SOP-002 · GOV-SOP-003", { size: 20, after: 0 }), { w: 3040 })] })
      ]}),
      spacer(240, 0), divider(), spacer(200, 0),

      sectionHdr('1  Purpose'),
      info("Why This SOP Exists", "Approved reviews are published on the website — on the Reviews page and on the page of the service they praise — in the customer's own words and under the name we show. A review has two image fields that behave in opposite ways: Public image goes on the website exactly as uploaded; Scan of the original note is seen by the office only. A handwritten card carries a signature, often an address or phone number, that the customer never agreed to publish. This SOP exists so the words get typed, the card stays private, and nothing reaches the public page that the customer did not say or agree to."),
      spacer(200, 0),

      sectionHdr('2  Scope'),
      p([run('Applies to', { bold: true, color: C.green })], { after: 60 }),
      bullet("Every testimonial entered by the office, whether it arrived on paper, by email, in person, through the /review form or from a QR code on a job"),
      bullet("Approving, rejecting and editing reviews"),
      bullet("Handwritten cards and letters"),
      p([run('Does not apply to', { bold: true, color: C.red })], { before: 120, after: 60 }),
      bullet("Google reviews — customers leave those on Google; the /review page offers the Google button"),
      bullet("Public page design, which services show reviews, or the planned home-page strip — system administration"),
      bullet("Marketing copy written by us — reviews are the customer's words only"),
      spacer(200, 0),

      sectionHdr('3  Rules & Responsibilities'),
      p([run('MUST', { bold: true, color: C.white, size: 20 })], { after: 60, border: { left: { style: BorderStyle.SINGLE, size: 24, color: C.green, space: 6 } } }),
      bullet("The words of a review must be typed into Testimony, even when the original is handwritten. The published text is what customers, search engines and screen readers read; a picture of text is invisible to both."),
      bullet("A photo of a handwritten card must go into Scan of the original note, never into Public image. The scan is office-only inside BOS."),
      bullet("Anything placed in Public image must already have the signature, address, phone number and full name cropped or blurred — it publishes exactly as uploaded."),
      bullet("The name in Testimonial by must be what the customer agreed to show. First name and initial, or first name and town, unless they said otherwise. It appears under the quote."),
      bullet("The office must set Testimony of which Services to the exact service — the review appears on that service's page and nowhere higher up."),
      bullet("Only a review the office has read in full must be set to Approved. Submissions arrive as Pending; nothing is public until Approved."),
      bullet("Edits must be limited to spelling and obvious slips. The sense, the praise and the complaints stay the customer's."),
      p([run('MUST NOT', { bold: true, color: C.red, size: 20 })], { before: 160, after: 60 }),
      bullet("Do not upload the card photo as the Public image \"so people can see it is real\". That is how a signature gets published."),
      bullet("Do not type the customer's full name, address or phone number anywhere public — Testimony, Testimonial by or an image."),
      bullet("Do not rely on the \"Front Promoted\" tick to hide a review. Only Status controls the Reviews page; Front Promoted affects service pages only."),
      bullet("Do not copy or share the scan's file link. The scan is hidden in BOS, but the file itself sits on the public file store — anyone with the exact link could open it until that is changed."),
      bullet("Do not publish an AI transcript unread. Fix every [?] and read the whole thing against the card first."),
      spacer(200, 0),

      sectionHdr('4  Prerequisites'),
      bullet("Office administration role (create and edit testimonials, approve)"),
      bullet("The review in hand: the card or letter, the email, or the Pending item in the Reviews list"),
      bullet("The customer's agreement to be quoted, and how they want to be named"),
      spacer(200, 0),

      sectionHdr('5  Steps & Procedures'),
      p([run('Overview', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      pipelineRow([{ label: "Receive", desc: "Card, email, /review form or job QR → Pending." }, { label: "Enter", desc: "Type Testimony; name; exact service; scan to the office-only field." }, { label: "Image", desc: "Public image only if cropped/blurred — or none." }, { label: "Approve", desc: "Read it whole; Status = Approved." }, { label: "Check", desc: "It shows on /about-us/reviews and the service page." }]),
      spacer(160, 0),
      p([run('Pre-Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("You have the customer's words in front of you, complete."),
      bullet("You know which single service the review is about."),
      bullet("You know how the customer wants to be named."),
      spacer(120, 0),
      p([run('Steps', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      stepTable([
        { num: 1, title: "Open Reviews", who: "Office", items: ["Office ▸ Reviews (/admin/office/reviews) — all reviews; tabs Pending and Approved. Submissions from the /review form and the job QR appear here as Pending.", "To enter a paper or emailed review, add a new testimonial from this page."] },
        { num: 2, title: "Type the review", who: "Office", items: ["Testimony: the customer's words, typed in full. Spelling fixes only.", "Testimonial by: the name as agreed — e.g. \"Karen M., Golden\". This shows under the quote.", "Testimony of which Services: the exact service (Repair, not Sprinkler System). The review shows on that service's page.", "Office-only fields: Customer (their account, if any), Work order (when the QR carried it), Submitter email (never published; for a thank-you or to verify)."] },
        { num: 3, title: "Handwritten card or letter", who: "Office", items: ["Photograph or scan the card and attach it to Scan of the original note (several files allowed: second page, envelope). This field is never shown to the public.", "Type the words into Testimony yourself, or use Read the note in the list's Actions menu (shown only when a scan is attached). It drafts a transcript into Testimony and the signer into Testimonial by, marks unreadable words [?], and leaves out the signature, address and phone.", "Read the draft against the card. Fix every [?]. These publish as the customer's own words."] },
        { num: 4, title: "Public image — only when it is safe", who: "Office", items: ["Most reviews need no image. Leave Public image empty.", "If a photo of the card should appear publicly: in Read the note, choose the line for Blur everything below this point and check the live preview (the default 70% is there because 80% left a signature readable). Tick Use this blurred image as the Public image only when the preview shows no signature, address, phone or full name.", "For a PDF or a type the tool cannot preview, crop the image yourself before uploading, or leave Public image empty."] },
        { num: 5, title: "Approve", who: "Office", items: ["Read the whole review as it will publish. Set Status to Approved and save. A review created by anyone outside the office is forced back to Pending on save until the office approves it.", "Leave Front Promoted ticked (the default) if the review may appear on its service page; untick it to keep it off service pages while still on the Reviews page."] },
        { num: 6, title: "Check the public result", who: "Office", items: ["Open /about-us/reviews — newest first — and the service's own page. Confirm the words, the name and the absence of anything private.", "If the review is about a job, the office may thank the customer using Submitter email; that address is never published."] }
      ]),
      spacer(160, 0),
      block("Two image fields, opposite behaviour", "Public image renders on the website exactly as uploaded. Scan of the original note is office-only. A card photo in the wrong field publishes a customer's signature and often an address. When in doubt, no public image at all."),
      spacer(80, 0),
      warn("The scan file is private in BOS, not on disk", "BOS hides the scan from everyone outside the office, but the uploaded file is stored on the public file store, so the exact file link would open for anyone who had it. Never copy or send that link. Moving scans to private storage is logged as deferred work."),
      spacer(80, 0),
      info("Where a review appears", "The Reviews page shows every Approved review, newest first. A service page shows only Approved reviews whose service is exactly that page and whose Front Promoted tick is on. The home-page strip is not built."),
      spacer(160, 0),
      p([run('Quality Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("Testimony holds typed words — no review is an image only."),
      bullet("No Public image shows a signature, address, phone number or full name."),
      bullet("Every Approved review names one exact service and shows on that service's page."),
      bullet("The Pending tab is empty at the end of the week."),
      p([run('Completion', { bold: true, color: C.greenDk, size: 24 })], { before: 120, after: 60 }),
      bullet("Approved review visible on /about-us/reviews and the service page."),
      bullet("Card filed, scan attached to the office-only field, customer thanked if appropriate."),
      spacer(160, 0),
      dodonts([["Upload the card as the Public image", "Scan → office-only field; type the words; public image only when blurred and checked"], ["Publish the AI transcript as is", "Fix every [?] against the card first"], ["Write \"Karen Mitchell, 1234 Elm St\" under the quote", "\"Karen M., Golden\" — what she agreed to"], ["Untick Front Promoted to take a review down", "Set Status to Rejected or back to Pending"]]),
      spacer(200, 0),

      sectionHdr('6  Tools & Resources'),
      bullet("Office ▸ Reviews — All / Pending / Approved (/admin/office/reviews)"),
      bullet("Read the note — AI transcript + blur preview (Actions menu, when a scan is attached)"),
      bullet("Public pages: /about-us/reviews and each service page"),
      bullet("Review QR for a job: /admin/operations/system_content/testimonials/qr?wo=NNN"),
      spacer(200, 0),

      sectionHdr('7  Key Performance Indicators'),
      kpiTable([{ indicator: "Approved reviews with typed Testimony", target: "100%", owner: "Office" }, { indicator: "Public images carrying a signature, address, phone or full name", target: "0 — checked on every approval", owner: "Office" }, { indicator: "Pending reviews older than 7 days", target: "0", owner: "Office" }, { indicator: "Reviews placed on the wrong service page", target: "0", owner: "Office" }]),
      spacer(200, 0),

      sectionHdr('8  Related SOPs'),
      bullet("SOP-WEB-002 — Services URL Structure – Public Website"),
      bullet("GOV-SOP-001 — SOP Creation & Management"),
      spacer(200, 80), divider(), spacer(80, 0),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 0 }, children: [run("Brookstone Outdoors LLC  ·  OFF-ADM-REV-001 v1.0  ·  November 2026  ·  Internal Use Only", { color: '9E9E9E', size: 18, italic: true })] }),
    ],
  }],
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync(__dirname + '/OFF-ADM-REV-001_v1.0.docx', buf);
  console.log('Done OFF-ADM-REV-001');
});
