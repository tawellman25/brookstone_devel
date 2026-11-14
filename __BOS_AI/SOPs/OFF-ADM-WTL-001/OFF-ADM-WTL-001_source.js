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
// DOCUMENT — OFF-ADM-WTL-001 (Customer-Portal Waitlist: Finding and Working the Leads) · generated 2026-11-13 by Code at Todd's
// request; the entity content lives in OFF-ADM-WTL-001_fields.json (same source).
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
      children: [run('BROOKSTONE OUTDOORS  ', { bold: true, color: C.green, size: 18 }), run("|  OFF-ADM-WTL-001  |  Customer-Portal Waitlist: Finding and Working the Leads", { color: '757575', size: 18 })],
    })]}) },
    footers: { default: new Footer({ children: [new Paragraph({
      spacing: { before: 80, after: 0 },
      border: { top: { style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 } },
      tabStops: [{ type: TabStopType.RIGHT, position: TabStopPosition.MAX }],
      children: [run("OFF-ADM-WTL-001 v1.0  ·  November 2026  ·  Internal Use Only  ", { color: '9E9E9E', size: 18 }), run("\tOffice Administration SOP", { color: '9E9E9E', size: 18 })],
    })]}) },
    children: [
      spacer(0, 480),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 40 }, children: [run('BROOKSTONE OUTDOORS', { bold: true, color: C.green, size: 52 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 160 }, children: [run('Creating and Maintaining Your Outdoor Spaces', { italic: true, color: '757575', size: 24 })] }),
      divider(), spacer(120, 80),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 60 }, children: [run('STANDARD OPERATING PROCEDURE', { bold: true, color: C.greenDk, size: 36 })] }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 240 }, children: [run("OFF-ADM-WTL-001 — Customer-Portal Waitlist: Finding and Working the Leads", { bold: true, color: C.gray, size: 28 })] }),
      new Table({ width: { size: 5040, type: WidthType.DXA }, columnWidths: [2000, 3040], rows: [
        new TableRow({ children: [cell(p("SOP Code", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("OFF-ADM-WTL-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Bundle", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("office_administration", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Version", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("1.0", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Effective", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("November 2026 (Draft until approved)", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Applies To", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("Office administration", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Related SOPs", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("OFF-ADM-EST-001", { size: 20, after: 0 }), { w: 3040 })] }),
        new TableRow({ children: [cell(p("Governance", { bold: true, color: '757575', size: 20, after: 0 }), { w: 2000, fill: C.grayLt }), cell(p("GOV-SOP-001 · GOV-SOP-002 · GOV-SOP-003", { size: 20, after: 0 }), { w: 3040 })] })
      ]}),
      spacer(240, 0), divider(), spacer(200, 0),

      sectionHdr('1  Purpose'),
      info("Why This SOP Exists", "The login page carries a public \"Coming for customers\" panel. Anyone who signs up becomes, or updates, a Contact in BOS with their portal interest and consent ticks recorded. These are warm leads — people asking for an account, often with a service address and a phone number — and they are also a consent record. This SOP exists so the office can find them, read them correctly, and work them without emailing anyone before BOS can prove consent at send time."),
      spacer(200, 0),

      sectionHdr('2  Scope'),
      p([run('Applies to', { bold: true, color: C.green })], { after: 60 }),
      bullet("Every Contact with Portal waitlist interest = Interested"),
      bullet("Office staff who follow up on leads or prepare the \"accounts are open\" announcement"),
      bullet("The consent flags the sign-up sets on the Contact"),
      p([run('Does not apply to', { bold: true, color: C.red })], { before: 120, after: 60 }),
      bullet("Building or launching the portal itself"),
      bullet("Changing a customer's login email on their User account — admin only, client email policy"),
      bullet("Marketing emails to anyone else — BOS sends no customer email until the send-time consent check exists"),
      spacer(200, 0),

      sectionHdr('3  Rules & Responsibilities'),
      p([run('MUST', { bold: true, color: C.white, size: 20 })], { after: 60, border: { left: { style: BorderStyle.SINGLE, size: 24, color: C.green, space: 6 } } }),
      bullet("The office must work leads from the Portal waitlist list (Office ▸ Portal waitlist), not from memory or a spreadsheet."),
      bullet("Before contacting a lead the office must read the Portal waitlist details note: mobile, service address, whether they say they are already a customer, campaign, and when they signed up."),
      bullet("The office must treat the consent flags exactly as set: signing up grants Service email OK only. Marketing email OK and Service text/SMS OK are set only when the person ticked those boxes."),
      bullet("A follow-up that is not a reply to the person's own request must not go out by email until BOS has a send-time consent check. Phone follow-up is the channel today."),
      bullet("Anything learned on a follow-up (confirmed property, \"already a customer\" verified, do-not-contact) must be recorded on the Contact — in Contact Notes for lasting context, never by editing the waitlist details note."),
      bullet("The office must check for a duplicate Contact before creating anything for a lead: the sign-up matches on email first, then last name plus phone; a lead who typed a new email may already exist under an old one."),
      p([run('MUST NOT', { bold: true, color: C.red, size: 20 })], { before: 160, after: 60 }),
      bullet("Do not email any address ending in @brookstoneoutdoors.com or @sewardslandscape.com — those are placeholder addresses, not the customer's."),
      bullet("Do not delete a Contact, even a duplicate. Note the duplicate; de-duplication is a portal-build task."),
      bullet("Do not flip an existing \"No\" on any opt-in flag because a person signed up again. The form itself never does; neither does the office."),
      bullet("Do not treat portal interest as marketing consent. It is a product signal and is deliberately kept out of the consent log."),
      spacer(200, 0),

      sectionHdr('4  Prerequisites'),
      bullet("Office administration (or supervisor / site admin) role — needed to view and edit Contacts"),
      bullet("Office ▸ Portal waitlist list is reachable"),
      bullet("The client email policy has been read (Governance/client_email_policy.md)"),
      spacer(200, 0),

      sectionHdr('5  Steps & Procedures'),
      p([run('Overview', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      pipelineRow([{ label: "Find", desc: "Office ▸ Portal waitlist — newest sign-ups first." }, { label: "Read", desc: "Details note + consent flags on the Contact." }, { label: "Verify", desc: "Already a customer? Match property; check for duplicates." }, { label: "Follow up", desc: "Phone. Record the outcome in Contact Notes." }, { label: "Launch", desc: "When the portal opens: consent-checked announcement, built later." }]),
      spacer(160, 0),
      p([run('Pre-Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("You are looking at the Portal waitlist list, not the full Contacts list."),
      bullet("The lead's Contact page is open before you call."),
      spacer(120, 0),
      p([run('Steps', { bold: true, color: C.greenDk, size: 24 })], { after: 80 }),
      stepTable([
        { num: 1, title: "Open the list", who: "Office", items: ["Office ▸ Portal waitlist (/admin/office/portal-waitlist). Every Contact with Portal waitlist interest = Interested, newest sign-up first, with email, sign-up date, the details note and the three consent flags.", "Filter by name or sign-up date when you are looking for one person."] },
        { num: 2, title: "Read the lead", who: "Office", items: ["Open the Contact. Portal waitlist details holds one line each: Mobile, Service address, Already a customer: yes | no | not_sure, Campaign, Signed up. A second sign-up overwrites this note, so copy anything you need into Contact Notes.", "Read the flags: Service email OK (always set by signing up), Marketing email OK and Service text/SMS OK (only if ticked). Consent last updated and Consent source = Web form say when and how."] },
        { num: 3, title: "Verify who they are", who: "Office", items: ["If they say they are already a customer, find their property and customer account and confirm the Contact is attached to them. \"Already a customer\" is self-reported.", "If the Contact looks like a duplicate of an existing one (same person, different email), note it in Contact Notes on both. Do not delete either.", "The mobile number is in the note only — it is not a phone record yet. Add it as a phone number on the Contact only if you have confirmed it with the person."] },
        { num: 4, title: "Follow up", who: "Office", items: ["Call when a lead needs a reply (a service address we do not serve, an address question, a request for work). Email only in reply to their own question, and only to a real address.", "Record the outcome in Contact Notes as lasting context (\"Portal lead, Bear Creek, wants spring start-up\"), not as a dated call log."] },
        { num: 5, title: "When the portal opens", who: "Office / Todd", items: ["The \"accounts are open\" email to the list is sent only through a send-time consent check (opt-in flags plus a real external address). That check is not built yet; until it is, nothing goes out to the list."] }
      ]),
      spacer(160, 0),
      block("No customer email from BOS today", "BOS currently sends no email to customers. The waitlist is not an exception: the announcement waits for the send-time consent check. Phone is the follow-up channel."),
      spacer(80, 0),
      warn("The details note is overwritten on resubmit", "If the same person signs up twice, the earlier mobile, address and answers are gone. Copy what matters into Contact Notes when you first read a lead."),
      spacer(80, 0),
      info("What a sign-up can change on a Contact", "A matched Contact gets its email replaced only if the stored one was blank or a placeholder; names are filled only where empty. Nothing is ever created or changed on a User account."),
      spacer(160, 0),
      p([run('Quality Checks', { bold: true, color: C.greenDk, size: 24 })], { after: 60 }),
      bullet("Every lead on the list has been opened and read within a week of signing up."),
      bullet("Contact Notes carries the outcome for every lead that was followed up."),
      bullet("No email went to a placeholder address or without a matching consent flag."),
      bullet("Duplicates are noted, not deleted."),
      p([run('Completion', { bold: true, color: C.greenDk, size: 24 })], { before: 120, after: 60 }),
      bullet("Lead read and verified; outcome in Contact Notes."),
      bullet("Nothing else to do until the portal launch plan is built."),
      spacer(160, 0),
      dodonts([["Export the list and email everyone \"good news coming\"", "Wait for the consent-checked announcement; phone the ones who asked a question"], ["Edit the details note to add what the customer told you", "Put it in Contact Notes — the details note belongs to the form"], ["Delete the obvious duplicate", "Note it on both Contacts"], ["Trust \"Already a customer: yes\"", "Find the property and confirm"]]),
      spacer(200, 0),

      sectionHdr('6  Tools & Resources'),
      bullet("Office ▸ Portal waitlist — the lead list (/admin/office/portal-waitlist)"),
      bullet("The Contact record — Portal waitlist details, consent flags, Contact Notes"),
      bullet("The login page panel itself (/user/login) — to see what the customer saw"),
      bullet("Governance/client_email_policy.md — which addresses are real"),
      spacer(200, 0),

      sectionHdr('7  Key Performance Indicators'),
      kpiTable([{ indicator: "Leads opened within 7 days of sign-up", target: "100%", owner: "Office" }, { indicator: "Leads with a follow-up outcome in Contact Notes", target: "100% of those contacted", owner: "Office" }, { indicator: "Emails sent to the list before the consent check exists", target: "0", owner: "Office" }, { indicator: "Duplicate Contacts created by hand for a lead", target: "0", owner: "Office" }]),
      spacer(200, 0),

      sectionHdr('8  Related SOPs'),
      bullet("OFF-ADM-EST-001 — Estimate Request Intake & Pipeline"),
      bullet("GOV-SOP-001 — SOP Creation & Management"),
      spacer(200, 80), divider(), spacer(80, 0),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 0, after: 0 }, children: [run("Brookstone Outdoors LLC  ·  OFF-ADM-WTL-001 v1.0  ·  November 2026  ·  Internal Use Only", { color: '9E9E9E', size: 18, italic: true })] }),
    ],
  }],
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync(__dirname + '/OFF-ADM-WTL-001_v1.0.docx', buf);
  console.log('Done OFF-ADM-WTL-001');
});
