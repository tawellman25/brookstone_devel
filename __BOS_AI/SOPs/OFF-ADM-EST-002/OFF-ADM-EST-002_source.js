const {
  Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
  HeadingLevel, AlignmentType, BorderStyle, WidthType, ShadingType,
  LevelFormat, Header, Footer, TabStopType, TabStopPosition
} = require('docx');
const fs = require('fs');

const C = {
  green:'2E7D32',greenDk:'1B5E20',greenLt:'E8F5E9',
  blue:'0D47A1',blueMid:'1565C0',blueLt:'E3F2FD',
  gold:'F57F17',goldLt:'FFF8E1',
  red:'B71C1C',redLt:'FFEBEE',
  purple:'4A148C',purpleLt:'F3E5F5',
  gray:'424242',grayLt:'F5F5F5',grayMid:'EEEEEE',
  white:'FFFFFF',border:'CCCCCC',
};

const bdr = (c=C.border)=>({style:BorderStyle.SINGLE,size:1,color:c});
const borders = (c=C.border)=>({top:bdr(c),bottom:bdr(c),left:bdr(c),right:bdr(c)});
const noBorders = ()=>({top:bdr('FFFFFF'),bottom:bdr('FFFFFF'),left:bdr('FFFFFF'),right:bdr('FFFFFF')});

const cell=(children,{w=4680,fill,bc=C.border,nb=false,vAlign='top',cs}={})=>
  new TableCell({columnSpan:cs,borders:nb?noBorders():borders(bc),width:{size:w,type:WidthType.DXA},
    shading:fill?{fill,type:ShadingType.CLEAR}:undefined,
    margins:{top:90,bottom:90,left:140,right:140},verticalAlign:vAlign,
    children:Array.isArray(children)?children:[children]});

const p=(text,{bold,italic,color,size=22,before=0,after=120,align,heading,border:pb}={})=>
  new Paragraph({heading,alignment:align,spacing:{before,after},border:pb,
    children:Array.isArray(text)?text:[new TextRun({text,bold,italics:italic,color,size,font:'Arial'})]});

const run=(text,{bold,italic,color,size=22}={})=>
  new TextRun({text,bold,italics:italic,color,size,font:'Arial'});

const spacer=(before=80,after=80)=>p('',{before,after});
const divider=(color=C.green)=>new Paragraph({spacing:{before:120,after:120},
  border:{bottom:{style:BorderStyle.SINGLE,size:6,color,space:1}},
  children:[new TextRun({text:'',font:'Arial',size:4})]});

const bullet=(text,level=0)=>new Paragraph({numbering:{reference:'bullets',level},
  spacing:{before:30,after:30},
  children:Array.isArray(text)?text:[new TextRun({text,font:'Arial',size:22})]});

const sectionHdr=(label)=>new Paragraph({spacing:{before:320,after:140},
  border:{bottom:{style:BorderStyle.SINGLE,size:6,color:C.green,space:4}},
  children:[new TextRun({text:label,bold:true,color:C.greenDk,size:28,font:'Arial'})]});

// Stage pipeline (10 stages with colors)
const stagePipeline=()=>{
  const stages=[
    {num:1,label:'New',          fill:'6C757D',desc:'Assigned to you. Customer not yet contacted.'},
    {num:2,label:'Contacted',    fill:'1565C0',desc:'You have made first contact with the customer.'},
    {num:3,label:'Appointment Set',fill:'006064',desc:'Site visit scheduled. Date and time confirmed.'},
    {num:4,label:'In Preparation',fill:'E65100',desc:'Property visited. Building the estimate in BOS.'},
    {num:5,label:'Under Review', fill:'4A148C',desc:'Complete. Being reviewed before sending.'},
    {num:6,label:'Estimate Sent',fill:'F57F17',desc:'Proposal delivered to customer.'},
    {num:7,label:'Client Feedback',fill:'00695C',desc:'Customer has responded with questions or changes.'},
    {num:8,label:'Pending',      fill:'37474F',desc:'Customer is considering. No decision yet.'},
    {num:9,label:'Accepted',     fill:'2E7D32',desc:'Customer said yes. Moves off this board.'},
    {num:10,label:'Declined',    fill:'B71C1C',desc:'Customer said no. Moves off this board.'},
  ];
  const rows=[];
  stages.forEach((s,i)=>{
    rows.push(new TableRow({children:[
      cell(p([run(`${s.num}. `,{bold:true,color:C.white,size:19}),run(s.label,{bold:true,color:C.white,size:19})],
        {align:AlignmentType.CENTER,after:0}),{w:1800,fill:s.fill,bc:s.fill}),
      cell(p(s.desc,{color:C.gray,size:21,after:0}),{w:7560}),
    ]}));
    if(i<stages.length-1) rows.push(new TableRow({children:[
      cell(p('▼',{align:AlignmentType.CENTER,color:C.green,size:16,after:0,before:0}),{w:1800,nb:true}),
      cell(p('',{after:0}),{w:7560,nb:true}),
    ]}));
  });
  return new Table({width:{size:9360,type:WidthType.DXA},columnWidths:[1800,7560],rows});
};

const stepTable=(steps)=>{
  const rows=[];
  steps.forEach(s=>{
    rows.push(new TableRow({children:[
      cell(p(String(s.num),{bold:true,color:C.white,size:24,align:AlignmentType.CENTER,after:0}),
        {w:640,fill:C.green,bc:C.green,vAlign:'center'}),
      cell(p(s.title,{bold:true,color:C.greenDk,size:22,after:0}),{w:6400,fill:C.greenLt,bc:C.green}),
      cell(p(s.who,{italic:true,color:C.blue,size:19,align:AlignmentType.RIGHT,after:0}),
        {w:2320,fill:C.greenLt,bc:C.green}),
    ]}));
    rows.push(new TableRow({children:[
      cell(p('',{after:0}),{w:640,fill:C.grayLt,bc:C.border}),
      cell(s.items.map(item=>
        Array.isArray(item)
          ?new Paragraph({numbering:{reference:'bullets',level:0},spacing:{before:24,after:24},children:item})
          :bullet(item)
      ),{w:8720,bc:C.border,cs:2}),
    ]}));
  });
  return new Table({width:{size:9360,type:WidthType.DXA},columnWidths:[640,6400,2320],rows});
};

const alertBox=(icon,title,text,fill,titleColor,bc)=>
  new Table({width:{size:9360,type:WidthType.DXA},columnWidths:[9360],rows:[
    new TableRow({children:[cell([
      p([run(`${icon}  ${title}`,{bold:true,color:titleColor,size:22})],{after:60}),
      p(text,{size:22,after:0}),
    ],{w:9360,fill,bc})]})
  ]});

const warn =(t,x)=>alertBox('⚠',t,x,C.goldLt,C.gold,C.gold);
const block=(t,x)=>alertBox('✕',t,x,C.redLt,C.red,C.red);
const info =(t,x)=>alertBox('ℹ',t,x,C.blueLt,C.blue,C.blue);

const dodonts=(pairs)=>{
  const rows=[new TableRow({children:[
    cell(p("✕  Don't",{bold:true,color:C.white,size:22,after:0}),{w:4680,fill:C.red,bc:C.red}),
    cell(p("✓  Do",{bold:true,color:C.white,size:22,after:0}),{w:4680,fill:C.green,bc:C.green}),
  ]})];
  pairs.forEach(([bad,good])=>rows.push(new TableRow({children:[
    cell(p(bad,{color:'5D4037',size:20,after:0}),{w:4680,bc:'FFCDD2'}),
    cell(p(good,{color:'1B5E20',size:20,after:0}),{w:4680,bc:'C8E6C9'}),
  ]})));
  return new Table({width:{size:9360,type:WidthType.DXA},columnWidths:[4680,4680],rows});
};

const kpiTable=(kpis)=>{
  const rows=[new TableRow({children:[
    cell(p('Indicator',{bold:true,color:C.white,size:20,after:0}),{w:4680,fill:C.greenDk,bc:C.greenDk}),
    cell(p('Target / Standard',{bold:true,color:C.white,size:20,after:0}),{w:2880,fill:C.greenDk,bc:C.greenDk}),
    cell(p('Owner',{bold:true,color:C.white,size:20,after:0}),{w:1800,fill:C.greenDk,bc:C.greenDk}),
  ]})];
  kpis.forEach((k,i)=>rows.push(new TableRow({children:[
    cell(p(k.indicator,{size:20,after:0}),{w:4680,fill:i%2===0?C.white:C.grayLt}),
    cell(p(k.target,{size:20,after:0}),{w:2880,fill:i%2===0?C.white:C.grayLt}),
    cell(p(k.owner,{size:20,italic:true,color:C.blue,after:0}),{w:1800,fill:i%2===0?C.white:C.grayLt}),
  ]})));
  return new Table({width:{size:9360,type:WidthType.DXA},columnWidths:[4680,2880,1800],rows});
};

// Scope summary example table
const scopeExampleTable=()=>new Table({width:{size:9360,type:WidthType.DXA},columnWidths:[4680,4680],rows:[
  new TableRow({children:[
    cell(p('✕  Unacceptable Scope',{bold:true,color:C.white,size:22,after:0}),{w:4680,fill:C.red,bc:C.red}),
    cell(p('✓  Acceptable Scope',{bold:true,color:C.white,size:22,after:0}),{w:4680,fill:C.green,bc:C.green}),
  ]}),
  new TableRow({children:[
    cell([
      p('"Landscaping project at this property."',{italic:true,color:'5D4037',size:20,after:40}),
      p('"Lawn care service."',{italic:true,color:'5D4037',size:20,after:40}),
      p('"Client wants some work done in the yard."',{italic:true,color:'5D4037',size:20,after:0}),
    ],{w:4680,bc:'FFCDD2'}),
    cell([
      p('"Remove existing river rock and weed mat in front bed (approx 400 sq ft). Install new weed barrier and 3" layer of brown river rock. Plant 4 arborvitae along north fence. Haul off all debris."',{italic:true,color:'1B5E20',size:20,after:0}),
    ],{w:4680,bc:'C8E6C9'}),
  ]}),
]});

const doc=new Document({
  numbering:{config:[
    {reference:'bullets',levels:[
      {level:0,format:LevelFormat.BULLET,text:'•',alignment:AlignmentType.LEFT,style:{paragraph:{indent:{left:440,hanging:280}}}},
      {level:1,format:LevelFormat.BULLET,text:'◦',alignment:AlignmentType.LEFT,style:{paragraph:{indent:{left:880,hanging:280}}}},
    ]},
    {reference:'numbers',levels:[
      {level:0,format:LevelFormat.DECIMAL,text:'%1.',alignment:AlignmentType.LEFT,style:{paragraph:{indent:{left:440,hanging:280}}}},
    ]},
  ]},
  styles:{
    default:{document:{run:{font:'Arial',size:22,color:'212121'}}},
    paragraphStyles:[
      {id:'Heading1',name:'Heading 1',basedOn:'Normal',next:'Normal',quickFormat:true,
        run:{size:36,bold:true,color:C.greenDk,font:'Arial'},
        paragraph:{spacing:{before:360,after:160},outlineLevel:0}},
      {id:'Heading2',name:'Heading 2',basedOn:'Normal',next:'Normal',quickFormat:true,
        run:{size:26,bold:true,color:C.green,font:'Arial'},
        paragraph:{spacing:{before:240,after:100},outlineLevel:1}},
    ],
  },
  sections:[{
    properties:{page:{size:{width:12240,height:15840},margin:{top:1080,right:1080,bottom:1080,left:1080}}},
    headers:{default:new Header({children:[new Paragraph({
      spacing:{before:0,after:80},
      border:{bottom:{style:BorderStyle.SINGLE,size:4,color:C.green,space:4}},
      children:[
        run('BROOKSTONE OUTDOORS  ',{bold:true,color:C.green,size:18}),
        run('|  OFF-EST-002  |  Estimate Stage Management (Estimator)',{color:'757575',size:18}),
      ],
    })]})},
    footers:{default:new Footer({children:[new Paragraph({
      spacing:{before:80,after:0},
      border:{top:{style:BorderStyle.SINGLE,size:4,color:C.green,space:4}},
      tabStops:[{type:TabStopType.RIGHT,position:TabStopPosition.MAX}],
      children:[
        run('OFF-EST-002 v1.0  ·  April 2026  ·  Internal Use Only  ',{color:'9E9E9E',size:18}),
        run('\tOffice Administration SOP',{color:'9E9E9E',size:18}),
      ],
    })]})},
    children:[

      // COVER
      spacer(0,480),
      new Paragraph({alignment:AlignmentType.CENTER,spacing:{before:0,after:40},
        children:[run('BROOKSTONE OUTDOORS',{bold:true,color:C.green,size:52})]}),
      new Paragraph({alignment:AlignmentType.CENTER,spacing:{before:0,after:160},
        children:[run('Creating and Maintaining Your Outdoor Spaces',{italic:true,color:'757575',size:24})]}),
      divider(),
      spacer(120,80),
      new Paragraph({alignment:AlignmentType.CENTER,spacing:{before:0,after:60},
        children:[run('STANDARD OPERATING PROCEDURE',{bold:true,color:C.greenDk,size:36})]}),
      new Paragraph({alignment:AlignmentType.CENTER,spacing:{before:0,after:240},
        children:[run('OFF-EST-002 — Estimate Stage Management (Estimator)',{bold:true,color:C.gray,size:28})]}),
      new Table({width:{size:5040,type:WidthType.DXA},columnWidths:[2000,3040],rows:[
        new TableRow({children:[cell(p('SOP Code',{bold:true,color:'757575',size:20,after:0}),{w:2000,fill:C.grayLt}),cell(p('OFF-EST-002',{size:20,after:0}),{w:3040})]}),
        new TableRow({children:[cell(p('Bundle',{bold:true,color:'757575',size:20,after:0}),{w:2000,fill:C.grayLt}),cell(p('office_administration',{size:20,after:0}),{w:3040})]}),
        new TableRow({children:[cell(p('Version',{bold:true,color:'757575',size:20,after:0}),{w:2000,fill:C.grayLt}),cell(p('1.0',{size:20,after:0}),{w:3040})]}),
        new TableRow({children:[cell(p('Effective',{bold:true,color:'757575',size:20,after:0}),{w:2000,fill:C.grayLt}),cell(p('April 2026',{size:20,after:0}),{w:3040})]}),
        new TableRow({children:[cell(p('Applies To',{bold:true,color:'757575',size:20,after:0}),{w:2000,fill:C.grayLt}),cell(p('Estimators (all assigned estimates)',{size:20,after:0}),{w:3040})]}),
        new TableRow({children:[cell(p('Parent SOP',{bold:true,color:'757575',size:20,after:0}),{w:2000,fill:C.grayLt}),cell(p('OFF-EST-001 (Office Intake & Pipeline)',{size:20,after:0}),{w:3040})]}),
        new TableRow({children:[cell(p('Governance',{bold:true,color:'757575',size:20,after:0}),{w:2000,fill:C.grayLt}),cell(p('GOV-SOP-001',{size:20,after:0}),{w:3040})]}),
      ]}),
      spacer(240,0),
      divider(),
      spacer(200,0),

      // SECTION 1: PURPOSE
      sectionHdr('1  Purpose'),
      info('Why This SOP Exists',
        'This SOP controls how estimators receive, build, and advance estimates through the BOS My Estimates board. It exists to ensure every estimate is worked promptly, scoped accurately, and recorded in BOS before it goes to the customer — eliminating missed site visits, vague proposals, and untracked outcomes.'),
      spacer(200,0),

      // SECTION 2: SCOPE
      sectionHdr('2  Scope'),
      new Table({width:{size:9360,type:WidthType.DXA},columnWidths:[4680,4680],rows:[
        new TableRow({children:[
          cell(p('✓  Applies To',{bold:true,color:C.green,size:22,after:0}),{w:4680,fill:C.greenLt,bc:C.green}),
          cell(p('✕  Does Not Apply To',{bold:true,color:C.red,size:22,after:0}),{w:4680,fill:C.redLt,bc:C.red}),
        ]}),
        new TableRow({children:[
          cell(['All estimates assigned to the estimator via field_assigned_to','My Estimates board at /admin/office/estimates/my-estimates','All service types and estimate bundles','Scope summary (field_scope_summary) authoring on all estimate bundles that carry it'].map(t=>bullet(t)),{w:4680,bc:C.green}),
          cell(['Office pipeline management (see OFF-EST-001)','Contract creation or work order generation','Estimate request intake — that is the office role','Accepted and Declined record management'].map(t=>bullet(t)),{w:4680,bc:C.red}),
        ]}),
      ]}),
      spacer(200,0),

      // SECTION 3: RULES & RESPONSIBILITIES
      sectionHdr('3  Rules & Responsibilities'),
      new Table({width:{size:9360,type:WidthType.DXA},columnWidths:[360,9000],rows:[
        new TableRow({children:[
          cell(p('MUST',{bold:true,color:C.white,size:18,align:AlignmentType.CENTER,after:0}),
            {w:360,fill:C.green,bc:C.green,vAlign:'center'}),
          cell([
            bullet('Estimators must contact the customer within 1 business day of receiving an assigned estimate'),
            bullet('Estimators must advance the estimate stage on the My Estimates board each time the real-world status changes'),
            bullet('Estimators must visit the property before building the estimate — no remote pricing on site-dependent work'),
            bullet('Estimators must update field_scope_summary with specific, accurate project details before advancing past In Preparation'),
            bullet('Estimators must enter all line items (labor, materials, equipment) in BOS — not on paper'),
            bullet('Estimators must record the customer\'s response (Accepted or Declined) in BOS immediately when it occurs'),
          ],{w:9000}),
        ]}),
        new TableRow({children:[
          cell(p('MUST NOT',{bold:true,color:C.white,size:18,align:AlignmentType.CENTER,after:0}),
            {w:360,fill:C.red,bc:C.red,vAlign:'center'}),
          cell([
            bullet('Estimators must not leave an estimate in New stage for more than 1 business day without contacting the customer'),
            bullet('Estimators must not advance past In Preparation with placeholder scope text — the system will block this'),
            bullet('Estimators must not quote prices verbally without recording them in BOS'),
            bullet('Estimators must not change the estimate request board status (that is the office role — estimators only advance their estimate stages)'),
            bullet('Estimators must not mark an estimate Accepted or Declined without the customer\'s explicit response'),
          ],{w:9000}),
        ]}),
      ]}),
      spacer(200,0),

      // SECTION 4: PREREQUISITES
      sectionHdr('4  Prerequisites'),
      bullet('BOS account with estimator access (teammates role minimum)'),
      bullet('Access to My Estimates board at /admin/office/estimates/my-estimates'),
      bullet('Estimate assigned via field_assigned_to — confirmed by BOS email notification'),
      bullet('Familiarity with OFF-EST-001 — must understand the office handoff and what "Ready to Estimate" means'),
      bullet('Measuring tools and site visit capability for site-dependent estimates'),
      spacer(200,0),

      // SECTION 5: STEPS & PROCEDURES
      sectionHdr('5  Steps & Procedures'),

      p('The Estimate Stage Board at a Glance',{heading:HeadingLevel.HEADING_2,before:100}),
      p('Your My Estimates board groups all assigned estimates by stage. Use the ← Back and Next Stage → buttons on each row to advance stages without leaving the page.',{after:120}),
      stagePipeline(),
      spacer(160,0),

      p('Pre-Checks',{heading:HeadingLevel.HEADING_2,before:100}),
      bullet('Confirm you are logged into BOS with your estimator account'),
      bullet('Go to My Estimates at /admin/office/estimates/my-estimates'),
      bullet('Check the New swimlane — any estimate here needs to be picked up today'),
      bullet('Check the ? help button on the My Estimates page for a reminder of what each stage means'),
      spacer(120,0),

      p('Step-by-Step Procedures',{heading:HeadingLevel.HEADING_2,before:100}),
      stepTable([
        {num:1,title:'Pick Up the Estimate',who:'Estimator',items:[
          'Go to My Estimates board — new assignments appear in the New swimlane',
          'Click the estimate title to open it and read the job details',
          'Read "Client Requested the Following" — this is what the office captured from the customer call',
          'Check the property address and confirm you can access the site',
          'Do not advance the stage until you have reviewed the job details',
        ]},
        {num:2,title:'Contact the Customer — advance to Contacted',who:'Estimator',items:[
          'Call the customer to introduce yourself and discuss the request',
          'Confirm what they are looking for — do not assume the office notes cover everything',
          'Once you have made first contact, click "Contacted →" on the My Estimates board row',
          'If the customer is unreachable, attempt contact at least twice before flagging to the office',
        ]},
        {num:3,title:'Schedule the Site Visit — advance to Appointment Set',who:'Estimator',items:[
          'Confirm a date and time for the site visit with the customer',
          'Click "Appointment Set →" on the board once date and time are confirmed',
          'Note any access requirements (gate codes, dogs, call ahead, etc.) — record in the estimate notes if relevant',
        ]},
        {num:4,title:'Visit the Property — advance to In Preparation',who:'Estimator',items:[
          'Visit the property at the scheduled time',
          'Take measurements, photos, and notes as needed',
          'Assess scope, conditions, and any constraints (drainage, slope, existing irrigation, etc.)',
          'For simple maintenance jobs (mowing, pre-emergent, etc.) — you may be able to quote on the spot',
          'After the visit, click "In Preparation →" on the board',
        ]},
        {num:5,title:'Build the Estimate in BOS',who:'Estimator',items:[
          'Open the estimate in BOS and add line items: labor, materials, equipment',
          'Calculate totals — field_estimate_total updates automatically from line items',
          [run('REQUIRED — Update the Scope Summary: ',{bold:true,color:C.red}),run('replace all placeholder text with a real description of the work. See the Scope Summary Rules section below.')],
          'For large or complex jobs — use field_estimate_type and phasing fields if applicable',
          'Double-check: does the total reflect what you would actually charge for this job?',
        ]},
        {num:6,title:'Scope Summary — Required Before Advancing',who:'Estimator',items:[
          'The system WILL BLOCK you from advancing past In Preparation if the scope is still placeholder text',
          'Open the estimate, find the Scope Summary field, and replace all auto-generated text',
          'Write exactly what will be done: which areas, what materials, what methods, what is excluded',
          'The scope is what the customer sees in the proposal — it must be accurate and specific',
        ]},
        {num:7,title:'Review and Advance to Estimate Sent',who:'Estimator / Office',items:[
          'If the estimate needs a second opinion — advance to "Under Review →" for internal review',
          'For straightforward jobs — advance directly to "Estimate Sent →" when the proposal is ready',
          'The office or estimator sends the proposal to the customer',
          'Advance immediately when sent — do not leave the estimate sitting in Estimate Sent',
        ]},
        {num:8,title:'Handle Customer Feedback',who:'Estimator / Office',items:[
          'If the customer responds with questions or revision requests — advance to "Client Feedback →"',
          'Address their feedback, update the estimate if needed, and re-send',
          'Advance back to "Estimate Sent →" after responding',
          'If the customer goes silent — advance to "Pending →" and notify the office to follow up',
        ]},
        {num:9,title:'Record the Outcome',who:'Estimator / Office',items:[
          [run('Customer accepts → ',{bold:true}),run('Click "Accepted →" on the board. For maintenance services, BOS automatically updates the linked contract section. For design-build, the office collects the deposit.')],
          [run('Customer declines → ',{bold:true}),run('Click ✕ on the board row. Confirm the dialog. The estimate moves off the My Estimates board to the Declined archive.')],
          'Never mark Accepted or Declined without the customer\'s explicit response',
        ]},
      ]),
      spacer(160,80),

      // Scope summary rules
      p('Scope Summary Rules',{heading:HeadingLevel.HEADING_2,before:100}),
      p('The Scope Summary (field_scope_summary) is the most important field you will fill out. It is the written record of what was agreed to and what the customer is paying for.',{after:120}),
      scopeExampleTable(),
      spacer(120,80),
      block('System Enforcement','The system will not let you advance an estimate past "In Preparation" if the Scope Summary contains placeholder text or is empty. This is not optional — it is a hard block. Update the scope after your site visit, before you try to advance the stage.'),
      spacer(160,0),

      p('Quality Checks',{heading:HeadingLevel.HEADING_2,before:100}),
      bullet('Every new estimate is picked up and moved to Contacted within 1 business day of assignment'),
      bullet('No estimate sits in In Preparation with placeholder scope text'),
      bullet('Every estimate that goes to the customer has a specific, accurate Scope Summary'),
      bullet('No verbal prices are given without a corresponding BOS estimate entry'),
      bullet('Accepted and Declined outcomes are recorded in BOS the same day the customer responds'),
      spacer(120,0),

      p('Completion',{heading:HeadingLevel.HEADING_2,before:100}),
      bullet('Estimate is in Accepted or Declined stage'),
      bullet('All line items are entered in BOS with correct totals'),
      bullet('Scope Summary reflects the actual agreed-upon work'),
      bullet('For Accepted estimates: BOS has automatically updated the linked contract section (maintenance) or the office has confirmed deposit receipt (design-build)'),
      spacer(160,0),

      p('Common Mistakes',{heading:HeadingLevel.HEADING_2,before:100}),
      dodonts([
        ['Leave an estimate in New for more than 1 day without calling','Call the customer the same day you receive the assignment email'],
        ['Leave scope as "CLIENT REQUEST (update after site visit…)"','Update the scope after your site visit with real project details'],
        ['Quote a price verbally without entering it into BOS','Enter all estimates in BOS — verbal quotes that aren\'t recorded don\'t exist'],
        ['Change the estimate request board status','Only advance your estimate stages — the office manages the request board'],
        ['Mark Accepted before the customer has actually said yes','Only mark Accepted or Declined after an explicit customer response'],
      ]),
      spacer(200,0),

      // SECTION 6: KPIs
      sectionHdr('6  Key Performance Indicators'),
      kpiTable([
        {indicator:'Time from assignment to first customer contact',target:'< 1 business day',owner:'Estimator'},
        {indicator:'Time in New stage',target:'< 1 business day before advancing to Contacted',owner:'Estimator'},
        {indicator:'Time in In Preparation',target:'< 5 business days before advancing',owner:'Estimator'},
        {indicator:'Scope Summary quality',target:'100% of estimates past In Preparation have specific scope text',owner:'Estimator'},
        {indicator:'BOS estimate entry rate',target:'100% of quoted jobs have a BOS estimate record',owner:'Estimator'},
        {indicator:'Same-day outcome recording',target:'Accepted/Declined recorded same day as customer response',owner:'Estimator'},
      ]),
      spacer(200,0),

      // SECTION 7: RELATED SOPs
      sectionHdr('7  Related SOPs'),
      bullet('OFF-EST-001 — Estimate Request Intake & Pipeline (Office Staff) — parent SOP'),
      bullet('GOV-SOP-001 — SOP Authoring Standard — governance'),
      spacer(200,80),
      divider(),
      spacer(80,0),
      new Paragraph({alignment:AlignmentType.CENTER,spacing:{before:0,after:0},
        children:[run('Brookstone Outdoors LLC  ·  OFF-EST-002 v1.0  ·  April 2026  ·  Internal Use Only',
          {color:'9E9E9E',size:18,italic:true})]}),
    ],
  }],
});

Packer.toBuffer(doc).then(buf=>{
  fs.writeFileSync(__dirname + '/OFF-ADM-EST-002_v1.0.docx',buf);
  console.log('Done');
});
