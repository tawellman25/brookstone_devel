#!/usr/bin/env python3
"""
BOS SOP draft generator — ONE content definition → three files per SOP:
  <CODE>_fields.json          (what sop_import_drafts.php enters into BOS)
  <CODE>_HTML_Fields_PASTE.html (human copy of the same HTML, per field)
  <CODE>_source.js            (docx generator; run inside DDEV, see SOP-AUTHORING-WORKFLOW.md)

  python3 __BOS_AI/SOPs/_gen/gen_sops.py            # writes every SOP in sop_content.py
  python3 __BOS_AI/SOPs/_gen/gen_sops.py MNT-PRT-001 # just one

Content lives in sop_content.py (SOPS list). First used 2026-11-11 (three WO
SOPs, scratchpad copy); committed 2026-11-13 with the eight-SOP batch so the
docx, the paste HTML and the BOS entity can be regenerated from one source.
Codes must satisfy the bundle validators (web/modules/custom/sop_*):
  system_procedures WEB-SYS-<SVC>-NNN · office_administration OFF-ADM-<SVC>-NNN
  sprinkler_maintenance IRR-MNT-<SVC>-NNN · maintenance MNT-<SVC>-NNN
  training TRN-<SVC>-NNN · safety SAF-… · landscaping LND-… · spray SPR-… · snow SNW-… · lighting LGT-…
"""
import json, pathlib, html, re, sys
ROOT = pathlib.Path(__file__).resolve().parent.parent
PRELUDE = '\n'.join((ROOT / 'OFF-ADM-EST-001/OFF-ADM-EST-001_source.js').read_text().splitlines()[:155]) + '\n'
MONTH = 'November 2026'
GENERATED = '2026-11-13'
E = html.escape
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from sop_content import SOPS

def info_box(title, text, fill='#E3F2FD', color='#0D47A1', icon='ℹ'):
    return f'<table style="width:100%;border-collapse:collapse;"><tr><td style="background:{fill};border-left:4px solid {color};padding:14px 18px;"><p style="margin:0 0 8px 0;font-weight:bold;color:{color};font-size:15px;">{icon}  {E(title)}</p><p style="margin:0;color:#424242;line-height:1.6;">{text}</p></td></tr></table>'
def ul(items, lh='1.8'):
    return '<ul style="margin:0;padding-left:20px;line-height:%s;">%s</ul>' % (lh, ''.join(f'<li>{i}</li>' for i in items))
def scope_html(s):
    return ('<table style="width:100%;border-collapse:collapse;"><tr>'
      f'<td style="width:50%;background:#E8F5E9;border:1px solid #2E7D32;padding:12px 16px;vertical-align:top;"><p style="margin:0 0 8px 0;font-weight:bold;color:#2E7D32;">✓  Applies To</p>{ul(map(E, s["applies_to"]))}</td>'
      f'<td style="width:50%;background:#FFEBEE;border:1px solid #B71C1C;padding:12px 16px;vertical-align:top;"><p style="margin:0 0 8px 0;font-weight:bold;color:#B71C1C;">✕  Does Not Apply To</p>{ul(map(E, s["not_to"]))}</td></tr></table>')
def rules_html(s):
    return (f'<table style="width:100%;border-collapse:collapse;margin-bottom:12px;"><tr><td style="width:80px;background:#2E7D32;color:#fff;font-weight:bold;text-align:center;padding:10px;vertical-align:middle;">MUST</td><td style="background:#fff;border:1px solid #CCCCCC;padding:12px 16px;vertical-align:top;">{ul(s["musts"], "1.9")}</td></tr></table>'
      f'<table style="width:100%;border-collapse:collapse;"><tr><td style="width:80px;background:#B71C1C;color:#fff;font-weight:bold;text-align:center;padding:10px;vertical-align:middle;font-size:13px;">MUST NOT</td><td style="background:#fff;border:1px solid #CCCCCC;padding:12px 16px;vertical-align:top;">{ul(s["mustnots"], "1.9")}</td></tr></table>')
def steps_html(s):
    fills = ['#1565C0', '#006064', '#4A148C', '#E65100', '#424242']
    out = ['<h3 style="color:#1B5E20;margin:0 0 8px 0;">Overview</h3><table style="width:100%;border-collapse:collapse;margin-bottom:20px;">']
    for i, (label, desc) in enumerate(s['pipeline']):
        out.append(f'<tr><td style="width:170px;background:{fills[i % 5]};color:#fff;font-weight:bold;text-align:center;padding:10px;">{i+1}. {E(label)}</td><td style="border:1px solid #CCCCCC;padding:10px 14px;color:#424242;">{E(desc)}</td></tr>')
    out.append('</table>')
    out.append(f'<h3 style="color:#1B5E20;margin:0 0 8px 0;">Pre-Checks</h3>{ul(map(E, s["prechecks"]))}')
    out.append('<h3 style="color:#1B5E20;margin:20px 0 8px 0;">Steps</h3>')
    for n, st in enumerate(s['steps'], 1):
        out.append(f'<table style="width:100%;border-collapse:collapse;margin-bottom:10px;"><tr><td style="width:44px;background:#2E7D32;color:#fff;font-weight:bold;text-align:center;font-size:16px;vertical-align:middle;">{n}</td><td style="background:#E8F5E9;border:1px solid #2E7D32;padding:8px 14px;font-weight:bold;color:#1B5E20;">{E(st["title"])}<span style="float:right;font-weight:normal;font-style:italic;color:#0D47A1;font-size:12px;">{E(st["who"])}</span></td></tr><tr><td style="background:#F5F5F5;"></td><td style="border:1px solid #CCCCCC;padding:10px 14px;">{ul(st["items"])}</td></tr></table>')
    kinds = {'warn': ('#FFF8E1', '#F57F17', '⚠'), 'block': ('#FFEBEE', '#B71C1C', '✕'), 'info': ('#E3F2FD', '#0D47A1', 'ℹ')}
    for kind, title, text in s['warnings']:
        f, c, ic = kinds[kind]
        out.append('<div style="margin:12px 0;">' + info_box(title, E(text), f, c, ic) + '</div>')
    out.append(f'<h3 style="color:#1B5E20;margin:20px 0 8px 0;">Quality Checks</h3>{ul(s["quality"])}')
    out.append(f'<h3 style="color:#1B5E20;margin:20px 0 8px 0;">Completion</h3>{ul(map(E, s["completion"]))}')
    out.append('<h3 style="color:#1B5E20;margin:20px 0 8px 0;">Do / Don\'t</h3><table style="width:100%;border-collapse:collapse;"><tr><td style="width:50%;background:#B71C1C;color:#fff;font-weight:bold;padding:8px 12px;">✕  Don\'t</td><td style="width:50%;background:#2E7D32;color:#fff;font-weight:bold;padding:8px 12px;">✓  Do</td></tr>')
    for bad, good in s['dodonts']:
        out.append(f'<tr><td style="border:1px solid #FFCDD2;padding:8px 12px;color:#5D4037;">{E(bad)}</td><td style="border:1px solid #C8E6C9;padding:8px 12px;color:#1B5E20;">{E(good)}</td></tr>')
    out.append('</table>')
    return ''.join(out)
def kpi_html(s):
    rows = ''.join(f'<tr><td style="border:1px solid #CCCCCC;padding:8px 12px;{"background:#F5F5F5;" if i % 2 else ""}">{E(a)}</td><td style="border:1px solid #CCCCCC;padding:8px 12px;{"background:#F5F5F5;" if i % 2 else ""}">{E(b)}</td><td style="border:1px solid #CCCCCC;padding:8px 12px;font-style:italic;color:#0D47A1;{"background:#F5F5F5;" if i % 2 else ""}">{E(c)}</td></tr>' for i, (a, b, c) in enumerate(s['kpis']))
    return f'<table class="table" style="border-collapse:collapse;width:100%;"><tbody><tr><td style="background-color:#1B5E20;color:#fff;padding:10px 14px;"><strong>Indicator</strong></td><td style="background-color:#1B5E20;color:#fff;padding:10px 14px;width:260px;"><strong>Target / Standard</strong></td><td style="background-color:#1B5E20;color:#fff;padding:10px 14px;width:120px;"><strong>Owner</strong></td></tr>{rows}</tbody></table>'
def tools_html(s):
    return ul(map(E, s['tools']))

NAMES = {s['code']: s['name'] for s in SOPS}
NAMES.update({'GOV-SOP-001': 'SOP Creation & Management', 'WEB-SYS-SGN-001': 'Sign-Off: Confirm Crew Hours and Decide Who Gets Billed', 'OFF-ADM-NBL-001': 'Non-Billable Time: Review and Correction', 'OFF-ADM-TGT-001': 'Target Hours on Cleanup Work Orders', 'OFF-ADM-EST-001': 'Estimate Request Intake & Pipeline', 'OFF-ADM-EST-002': 'Estimate Stage Management (Estimator)', 'MNT-FLT-001': 'Fleet maintenance', 'TRN-SYS-GOV-001': 'Training System Governance', 'SAF-GOV-SAF-001': 'Safety governance', 'SOP-WEB-002': 'Services URL Structure – Public Website'})

def paste_file(s, fields):
    blocks = []
    for fname, label, body in [('field_sop_purpose', 'Purpose', fields['field_sop_purpose']), ('field_sop_scope', 'Scope', fields['field_sop_scope']), ('field_sop_responsibilities', 'Rules &amp; Responsibilities', fields['field_sop_responsibilities']), ('field_prerequisites', 'Prerequisites (one value per line — plain text, multi-value)', '<pre>' + E('\n'.join(fields['field_prerequisites'])) + '</pre>'), ('field_sop_steps', 'Steps &amp; Procedures', fields['field_sop_steps']), ('field_sop_tools_and_resources', 'Tools &amp; Resources', fields['field_sop_tools_and_resources']), ('field_sop__kpis', 'Key Performance Indicators', fields['field_sop__kpis']), ('field_related_sops', 'Related SOPs (entity references)', '<pre>' + E('\n'.join(f"{c} — {NAMES.get(c, '')}" for c in s['related'])) + '</pre>')]:
        blocks.append(f'''
<!-- ─────────────────────────────────────────────────────────────────────────
     FIELD: {fname}
───────────────────────────────────────────────────────────────────────── -->
<h2>{fname} — {label}</h2>
<div class="field-block">
  <div class="field-label">Paste this HTML into</div>
  <div class="field-name">{fname}</div>
  <div class="paste-note">Switch to Source view in the CKEditor toolbar, then paste.</div>
  <div class="html-output">
{body}
  </div>
</div>
''')
    return f'''<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>{s['code']} HTML Field Content</title>
<style>
  body {{ font-family: Arial, sans-serif; font-size: 14px; color: #212121; max-width: 960px; margin: 0 auto; padding: 24px; }}
  h1 {{ color: #1B5E20; border-bottom: 3px solid #2E7D32; padding-bottom: 8px; }}
  h2 {{ color: #2E7D32; margin-top: 40px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }}
  .field-block {{ background: #f9f9f9; border: 1px solid #ddd; border-radius: 6px; padding: 20px; margin-bottom: 32px; }}
  .field-label {{ font-size: 11px; font-weight: bold; color: #777; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }}
  .field-name  {{ font-size: 13px; font-weight: bold; color: #0D47A1; margin-bottom: 12px; font-family: monospace; background: #E3F2FD; display: inline-block; padding: 2px 8px; border-radius: 4px; }}
  .paste-note  {{ font-size: 11px; color: #888; margin-bottom: 12px; }}
  .html-output {{ border: 2px dashed #ccc; padding: 16px; border-radius: 4px; background: white; }}
  pre {{ background: #263238; color: #ECEFF1; padding: 12px; border-radius: 4px; overflow-x: auto; font-size: 11px; white-space: pre-wrap; }}
</style>
</head>
<body>

<h1>{s['code']} — HTML Field Content</h1>
<p style="color:#555;">{E(s['name'])} · bundle <code>{s['bundle']}</code> · v1.0 · {MONTH} · status Draft until approved. Copy the HTML from each section into the matching BOS SOP field (Source view). Entered into BOS from {s['code']}_fields.json by web/scripts/sop_import_drafts.php — this file is the human-readable copy.</p>
{''.join(blocks)}
</body>
</html>
'''

def js(v):  # JSON is valid JS for strings/arrays
    return json.dumps(v, ensure_ascii=False)
def strip(h):  # HTML → plain text for the docx runs
    t = re.sub(r'<[^>]+>', '', h)
    return html.unescape(t)

def source_js(s):
    cover_rows = [('SOP Code', s['code']), ('Bundle', s['bundle']), ('Version', '1.0'), ('Effective', MONTH + ' (Draft until approved)'), ('Applies To', s['applies']), ('Related SOPs', ', '.join(c for c in s['related'] if c != 'GOV-SOP-001')), ('Governance', 'GOV-SOP-001 · GOV-SOP-002 · GOV-SOP-003')]
    cover = ',\n'.join(f"        new TableRow({{ children: [cell(p({js(k)}, {{ bold: true, color: '757575', size: 20, after: 0 }}), {{ w: 2000, fill: C.grayLt }}), cell(p({js(v)}, {{ size: 20, after: 0 }}), {{ w: 3040 }})] }})" for k, v in cover_rows)
    musts = ',\n'.join(f"      bullet({js(strip(m))})" for m in s['musts'])
    mustnots = ',\n'.join(f"      bullet({js(strip(m))})" for m in s['mustnots'])
    prereqs = ',\n'.join(f"      bullet({js(x)})" for x in s['prereqs'])
    pipeline = ', '.join(f"{{ label: {js(l)}, desc: {js(d)} }}" for l, d in s['pipeline'])
    prechecks = ',\n'.join(f"      bullet({js(x)})" for x in s['prechecks'])
    steps = ',\n'.join(f"        {{ num: {i}, title: {js(st['title'])}, who: {js(st['who'])}, items: {js([strip(x) for x in st['items']])} }}" for i, st in enumerate(s['steps'], 1))
    fn = {'warn': 'warn', 'block': 'block', 'info': 'info'}
    warnings = ',\n      spacer(80, 0),\n'.join(f"      {fn[k]}({js(t)}, {js(x)})" for k, t, x in s['warnings'])
    quality = ',\n'.join(f"      bullet({js(strip(x))})" for x in s['quality'])
    completion = ',\n'.join(f"      bullet({js(x)})" for x in s['completion'])
    dodonts = ', '.join(f"[{js(b)}, {js(g)}]" for b, g in s['dodonts'])
    tools = ',\n'.join(f"      bullet({js(x)})" for x in s['tools'])
    kpis = ', '.join(f"{{ indicator: {js(a)}, target: {js(b)}, owner: {js(c)} }}" for a, b, c in s['kpis'])
    related = ',\n'.join(f"      bullet({js(c + ' — ' + NAMES.get(c, ''))})" for c in s['related'])
    hdr = f"{s['code']}  |  {s['name']}"
    return PRELUDE + f'''
// ─────────────────────────────────────────────────────────────────────────────
// DOCUMENT — {s['code']} ({s['name']}) · generated {GENERATED} by Code at Todd's
// request; the entity content lives in {s['code']}_fields.json (same source).
// ─────────────────────────────────────────────────────────────────────────────
const doc = new Document({{
  numbering: {{
    config: [
      {{ reference: 'bullets', levels: [
        {{ level: 0, format: LevelFormat.BULLET, text: '•', alignment: AlignmentType.LEFT, style: {{ paragraph: {{ indent: {{ left: 440, hanging: 280 }} }} }} }},
        {{ level: 1, format: LevelFormat.BULLET, text: '◦', alignment: AlignmentType.LEFT, style: {{ paragraph: {{ indent: {{ left: 880, hanging: 280 }} }} }} }},
      ]}},
      {{ reference: 'numbers', levels: [
        {{ level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.LEFT, style: {{ paragraph: {{ indent: {{ left: 440, hanging: 280 }} }} }} }},
      ]}},
    ],
  }},
  styles: {{
    default: {{ document: {{ run: {{ font: 'Arial', size: 22, color: '212121' }} }} }},
    paragraphStyles: [
      {{ id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: {{ size: 36, bold: true, color: C.greenDk, font: 'Arial' }}, paragraph: {{ spacing: {{ before: 360, after: 160 }}, outlineLevel: 0 }} }},
      {{ id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: {{ size: 26, bold: true, color: C.green, font: 'Arial' }}, paragraph: {{ spacing: {{ before: 240, after: 100 }}, outlineLevel: 1 }} }},
    ],
  }},
  sections: [{{
    properties: {{ page: {{ size: {{ width: 12240, height: 15840 }}, margin: {{ top: 1080, right: 1080, bottom: 1080, left: 1080 }} }} }},
    headers: {{ default: new Header({{ children: [new Paragraph({{
      spacing: {{ before: 0, after: 80 }},
      border: {{ bottom: {{ style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 }} }},
      children: [run('BROOKSTONE OUTDOORS  ', {{ bold: true, color: C.green, size: 18 }}), run({js('|  ' + hdr)}, {{ color: '757575', size: 18 }})],
    }})]}}) }},
    footers: {{ default: new Footer({{ children: [new Paragraph({{
      spacing: {{ before: 80, after: 0 }},
      border: {{ top: {{ style: BorderStyle.SINGLE, size: 4, color: C.green, space: 4 }} }},
      tabStops: [{{ type: TabStopType.RIGHT, position: TabStopPosition.MAX }}],
      children: [run({js(s['code'] + ' v1.0  ·  ' + MONTH + '  ·  Internal Use Only  ')}, {{ color: '9E9E9E', size: 18 }}), run({js(chr(9) + s['bundle'].replace('_', ' ').title() + ' SOP')}, {{ color: '9E9E9E', size: 18 }})],
    }})]}}) }},
    children: [
      spacer(0, 480),
      new Paragraph({{ alignment: AlignmentType.CENTER, spacing: {{ before: 0, after: 40 }}, children: [run('BROOKSTONE OUTDOORS', {{ bold: true, color: C.green, size: 52 }})] }}),
      new Paragraph({{ alignment: AlignmentType.CENTER, spacing: {{ before: 0, after: 160 }}, children: [run('Creating and Maintaining Your Outdoor Spaces', {{ italic: true, color: '757575', size: 24 }})] }}),
      divider(), spacer(120, 80),
      new Paragraph({{ alignment: AlignmentType.CENTER, spacing: {{ before: 0, after: 60 }}, children: [run('STANDARD OPERATING PROCEDURE', {{ bold: true, color: C.greenDk, size: 36 }})] }}),
      new Paragraph({{ alignment: AlignmentType.CENTER, spacing: {{ before: 0, after: 240 }}, children: [run({js(s['code'] + ' — ' + s['name'])}, {{ bold: true, color: C.gray, size: 28 }})] }}),
      new Table({{ width: {{ size: 5040, type: WidthType.DXA }}, columnWidths: [2000, 3040], rows: [
{cover}
      ]}}),
      spacer(240, 0), divider(), spacer(200, 0),

      sectionHdr('1  Purpose'),
      info({js(s['purpose_title'])}, {js(strip(s['purpose']))}),
      spacer(200, 0),

      sectionHdr('2  Scope'),
      p([run('Applies to', {{ bold: true, color: C.green }})], {{ after: 60 }}),
{',\n'.join(f"      bullet({js(x)})" for x in s['applies_to'])},
      p([run('Does not apply to', {{ bold: true, color: C.red }})], {{ before: 120, after: 60 }}),
{',\n'.join(f"      bullet({js(x)})" for x in s['not_to'])},
      spacer(200, 0),

      sectionHdr('3  Rules & Responsibilities'),
      p([run('MUST', {{ bold: true, color: C.white, size: 20 }})], {{ after: 60, border: {{ left: {{ style: BorderStyle.SINGLE, size: 24, color: C.green, space: 6 }} }} }}),
{musts},
      p([run('MUST NOT', {{ bold: true, color: C.red, size: 20 }})], {{ before: 160, after: 60 }}),
{mustnots},
      spacer(200, 0),

      sectionHdr('4  Prerequisites'),
{prereqs},
      spacer(200, 0),

      sectionHdr('5  Steps & Procedures'),
      p([run('Overview', {{ bold: true, color: C.greenDk, size: 24 }})], {{ after: 80 }}),
      pipelineRow([{pipeline}]),
      spacer(160, 0),
      p([run('Pre-Checks', {{ bold: true, color: C.greenDk, size: 24 }})], {{ after: 60 }}),
{prechecks},
      spacer(120, 0),
      p([run('Steps', {{ bold: true, color: C.greenDk, size: 24 }})], {{ after: 80 }}),
      stepTable([
{steps}
      ]),
      spacer(160, 0),
{warnings},
      spacer(160, 0),
      p([run('Quality Checks', {{ bold: true, color: C.greenDk, size: 24 }})], {{ after: 60 }}),
{quality},
      p([run('Completion', {{ bold: true, color: C.greenDk, size: 24 }})], {{ before: 120, after: 60 }}),
{completion},
      spacer(160, 0),
      dodonts([{dodonts}]),
      spacer(200, 0),

      sectionHdr('6  Tools & Resources'),
{tools},
      spacer(200, 0),

      sectionHdr('7  Key Performance Indicators'),
      kpiTable([{kpis}]),
      spacer(200, 0),

      sectionHdr('8  Related SOPs'),
{related},
      spacer(200, 80), divider(), spacer(80, 0),
      new Paragraph({{ alignment: AlignmentType.CENTER, spacing: {{ before: 0, after: 0 }}, children: [run({js('Brookstone Outdoors LLC  ·  ' + s['code'] + ' v1.0  ·  ' + MONTH + '  ·  Internal Use Only')}, {{ color: '9E9E9E', size: 18, italic: true }})] }}),
    ],
  }}],
}});

Packer.toBuffer(doc).then(buf => {{
  fs.writeFileSync(__dirname + '/{s['code']}_v1.0.docx', buf);
  console.log('Done {s['code']}');
}});
'''

ONLY = set(sys.argv[1:])
for s in SOPS:
    if ONLY and s['code'] not in ONLY:
        continue
    d = ROOT / s['code']; d.mkdir(exist_ok=True)
    fields = {
        'field_sop_code': s['code'], 'field_name': s['name'], 'bundle': s['bundle'],
        'field_sop_purpose': info_box(s['purpose_title'], E(s['purpose'])),
        'field_sop_scope': scope_html(s),
        'field_sop_responsibilities': rules_html(s),
        'field_prerequisites': s['prereqs'],
        'field_sop_steps': steps_html(s),
        'field_sop_tools_and_resources': tools_html(s),
        'field_sop__kpis': kpi_html(s),
        'related_codes': s['related'], 'parent_code': s.get('parent', 'GOV-SOP-001'),
        'field_sop_version': '1.0', 'field_sop_status': 'draft', 'owner_uid': 1,
    }
    (d / f"{s['code']}_fields.json").write_text(json.dumps(fields, ensure_ascii=False, indent=1))
    (d / f"{s['code']}_HTML_Fields_PASTE.html").write_text(paste_file(s, fields))
    (d / f"{s['code']}_source.js").write_text(source_js(s))
    print(s['code'], 'written:', sorted(p.name for p in d.iterdir()))
