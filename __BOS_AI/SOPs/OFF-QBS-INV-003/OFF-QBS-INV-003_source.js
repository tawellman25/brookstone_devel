/**
 * OFF-QBS-INV-003 — Printing Customer Invoices in QuickBooks Desktop (CHILD SOP)
 * Parent: OFF-QBS-INV-001. Generates OFF-QBS-INV-003_v1.0.docx.
 * Run: ddev exec "NODE_PATH=/usr/local/lib/node_modules node /var/www/html/__BOS_AI/SOPs/OFF-QBS-INV-003/OFF-QBS-INV-003_source.js"
 */
const fs = require('fs');
const path = require('path');
const { Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell, WidthType, BorderStyle, AlignmentType } = require('docx');

const C = { green:'2E7D32', greenDark:'1B5E20', greenLight:'E8F5E9', blue:'0D47A1', blueLight:'E3F2FD', gold:'F57F17', goldLight:'FFF8E1', red:'B71C1C', redLight:'FFEBEE', gray:'424242', grayLight:'F5F5F5', white:'FFFFFF' };
const SOP_CODE='OFF-QBS-INV-003', SOP_TITLE='Printing Customer Invoices in QuickBooks Desktop', SOP_BUNDLE='office_administration', SOP_PARENT='OFF-QBS-INV-001', SOP_VERSION='1.0';
const NB={style:BorderStyle.NONE,size:0,color:'FFFFFF'}; const NBS={top:NB,bottom:NB,left:NB,right:NB};

function run(t,o={}){return new TextRun({text:t,size:o.size||21,bold:!!o.bold,italics:!!o.italics,color:o.color||C.gray});}
function sectionTitle(t){return new Paragraph({spacing:{before:260,after:100},border:{bottom:{style:BorderStyle.SINGLE,size:6,color:C.green}},children:[new TextRun({text:t,bold:true,size:26,color:C.greenDark})]});}
function body(t,o={}){return new Paragraph({spacing:{before:60,after:60},children:[run(t,o)]});}
function bullet(c,o={}){const r=Array.isArray(c)?c:[run(c,o)];return new Paragraph({bullet:{level:0},spacing:{before:30,after:30},children:r});}
function calloutBox(label,text,fill,bar,lc){return new Table({width:{size:100,type:WidthType.PERCENTAGE},borders:NBS,rows:[new TableRow({children:[new TableCell({shading:{fill},margins:{top:120,bottom:120,left:200,right:160},borders:{...NBS,left:{style:BorderStyle.SINGLE,size:24,color:bar}},children:[new Paragraph({children:[...(label?[new TextRun({text:label+'  ',bold:true,size:21,color:lc||bar})]:[]),new TextRun({text,size:21,color:C.gray})]})]})]})]});}
function hCell(t,w,a,fill){return new TableCell({width:{size:w,type:WidthType.PERCENTAGE},shading:{fill:fill||C.green},margins:{top:80,bottom:80,left:120,right:120},children:[new Paragraph({alignment:a||AlignmentType.LEFT,children:[new TextRun({text:t,bold:true,size:21,color:C.white})]})]});}
function metaRow(l,v){return new TableRow({children:[new TableCell({width:{size:28,type:WidthType.PERCENTAGE},shading:{fill:C.greenLight},margins:{top:60,bottom:60,left:120,right:120},borders:NBS,children:[new Paragraph({children:[new TextRun({text:l,bold:true,size:20,color:C.greenDark})]})]}),new TableCell({width:{size:72,type:WidthType.PERCENTAGE},margins:{top:60,bottom:60,left:120,right:120},borders:NBS,children:[new Paragraph({children:[new TextRun({text:v,size:20,color:C.gray})]})]})]});}
function stepRow(n,act,role,alt){const bg=alt?C.white:C.grayLight;return new TableRow({children:[new TableCell({width:{size:8,type:WidthType.PERCENTAGE},shading:{fill:bg},margins:{top:80,bottom:80,left:80,right:80},children:[new Paragraph({alignment:AlignmentType.CENTER,children:[new TextRun({text:String(n),bold:true,size:21,color:C.greenDark})]})]}),new TableCell({width:{size:72,type:WidthType.PERCENTAGE},shading:{fill:bg},margins:{top:80,bottom:80,left:120,right:120},children:[new Paragraph({children:act})]}),new TableCell({width:{size:20,type:WidthType.PERCENTAGE},shading:{fill:bg},margins:{top:80,bottom:80,left:120,right:120},children:[new Paragraph({children:[new TextRun({text:role,italics:true,size:19,color:C.blue})]})]})]});}
function ddRow(dont,doIt,alt){const bg=alt?C.white:C.grayLight;return new TableRow({children:[new TableCell({width:{size:50,type:WidthType.PERCENTAGE},shading:{fill:bg},margins:{top:80,bottom:80,left:120,right:120},children:[new Paragraph({children:[run(dont)]})]}),new TableCell({width:{size:50,type:WidthType.PERCENTAGE},shading:{fill:bg},margins:{top:80,bottom:80,left:120,right:120},children:[new Paragraph({children:[run(doIt)]})]})]});}
function kpiRow(ind,tgt,owner,alt){const bg=alt?C.white:C.grayLight;return new TableRow({children:[new TableCell({width:{size:52,type:WidthType.PERCENTAGE},shading:{fill:bg},margins:{top:80,bottom:80,left:120,right:120},children:[new Paragraph({children:[run(ind)]})]}),new TableCell({width:{size:28,type:WidthType.PERCENTAGE},shading:{fill:bg},margins:{top:80,bottom:80,left:120,right:120},children:[new Paragraph({children:[new TextRun({text:tgt,bold:true,size:21,color:C.blue})]})]}),new TableCell({width:{size:20,type:WidthType.PERCENTAGE},shading:{fill:bg},margins:{top:80,bottom:80,left:120,right:120},children:[new Paragraph({children:[new TextRun({text:owner,italics:true,size:19,color:C.blue})]})]})]});}

const children=[];
children.push(new Table({width:{size:100,type:WidthType.PERCENTAGE},borders:NBS,rows:[new TableRow({children:[new TableCell({shading:{fill:C.green},margins:{top:200,bottom:200,left:240,right:240},children:[new Paragraph({children:[new TextRun({text:SOP_CODE,bold:true,size:28,color:C.white})]}),new Paragraph({children:[new TextRun({text:SOP_TITLE,bold:true,size:24,color:C.greenLight})]})]})]})]}));
children.push(new Paragraph({spacing:{before:160,after:40},children:[]}));
children.push(new Table({width:{size:100,type:WidthType.PERCENTAGE},borders:NBS,rows:[metaRow('SOP Code',SOP_CODE),metaRow('SOP Type (Bundle)',SOP_BUNDLE),metaRow('Parent SOP',SOP_PARENT),metaRow('Version',SOP_VERSION)]}));

children.push(sectionTitle('Purpose'));
children.push(calloutBox('','Controls the risk of invoices being physically printed but not marked as printed in QuickBooks Desktop. Inaccurate printed status removes the office’s reliable view of sent versus outstanding invoices and creates the risk of missed billing and duplicate sends.',C.greenLight,C.green));

children.push(sectionTitle('Scope'));
children.push(new Table({width:{size:100,type:WidthType.PERCENTAGE},rows:[new TableRow({children:[hCell('Applies To',50),hCell('Does Not Apply To',50,null,C.red)]}),new TableRow({children:[new TableCell({width:{size:50,type:WidthType.PERCENTAGE},shading:{fill:C.grayLight},margins:{top:80,bottom:80,left:120,right:120},children:[new Paragraph({children:[run('Office and administrative staff printing customer invoices in QuickBooks Desktop.')]})]}),new TableCell({width:{size:50,type:WidthType.PERCENTAGE},shading:{fill:C.grayLight},margins:{top:80,bottom:80,left:120,right:120},children:[new Paragraph({children:[run('Emailed invoices, QuickBooks Online, and non-invoice forms (statements, POs, pay stubs).')]})]})]})]}));
children.push(body('Affected roles / systems: office staff; office manager (preference owner); QuickBooks Desktop.'));

children.push(sectionTitle('Rules & Responsibilities'));
children.push(body('All OFF-QBS-INV-001 (parent) rules apply.',{bold:true,color:C.blue}));
const M=()=>new TextRun({text:'MUST ',bold:true,color:C.greenDark,size:21});
const MN=()=>new TextRun({text:'MUST NOT ',bold:true,color:C.red,size:21});
children.push(bullet([M(),run('create invoices intended for print with the '),run('Print Later',{bold:true}),run(' checkbox checked.')]));
children.push(bullet([M(),run('print invoices from '),run('File > Print Forms > Invoices',{bold:true}),run(', not from the open invoice window.')]));
children.push(bullet([MN(),run('mark invoices printed by printing to PDF as a workaround.')]));
children.push(bullet([M(),run('confirm the print queue is clear of the intended invoices after each print run.')]));

children.push(sectionTitle('Prerequisites'));
['QuickBooks Desktop access with invoicing permissions','Send Forms delivery-method preferences configured by the office manager','An operational, configured printer available to QuickBooks'].forEach(p=>children.push(bullet(p)));

children.push(sectionTitle('Steps & Procedures'));
children.push(body('Pre-Checks',{bold:true,color:C.greenDark}));
children.push(bullet('Verify all prerequisites are met.'));
children.push(bullet([run('Confirm the target invoices have '),run('Print Later',{bold:true}),run(' checked.')]));
children.push(body('Steps',{bold:true,color:C.greenDark}));
children.push(new Table({width:{size:100,type:WidthType.PERCENTAGE},rows:[new TableRow({children:[hCell('#',8,AlignmentType.CENTER),hCell('Action',72),hCell('Role',20)]}),
  stepRow(1,[run('Create the invoice ('),run('Customers > Create Invoices',{bold:true}),run('); confirm '),run('Print Later',{bold:true}),run(' (bottom of the window) is checked; '),run('Save & Close',{bold:true}),run('.')],'Office Staff',false),
  stepRow(2,[run('Do not print from the open invoice window for routine printing.')],'Office Staff',true),
  stepRow(3,[run('When ready to print, go to '),run('File > Print Forms > Invoices',{bold:true}),run('.')],'Office Staff',false),
  stepRow(4,[run('Select the invoices to print, or '),run('Select All',{bold:true}),run('.')],'Office Staff',true),
  stepRow(5,[run('Click '),run('OK',{bold:true}),run(', confirm the printer, and '),run('Print',{bold:true}),run('.')],'Office Staff',false)]}));
children.push(new Paragraph({spacing:{before:80},children:[]}));
children.push(calloutBox('CAUTION:','Printing from the open invoice window does not reliably mark the invoice as printed. Routine printing must run from the Print Forms queue.',C.goldLight,C.gold,C.gold));
children.push(body('Quality Checks',{bold:true,color:C.greenDark}));
children.push(bullet([run('Return to '),run('File > Print Forms > Invoices',{bold:true}),run(' and confirm the printed invoices no longer appear in the list.')]));
children.push(bullet([run('For a single reprint, answer the “Do you want to mark this invoice as printed?” prompt '),run('Yes',{bold:true}),run(' only when resetting the flag is intended.')]));
children.push(body('Completion',{bold:true,color:C.greenDark}));
children.push(bullet('The print queue contains no invoices intended for this run.'));
children.push(bullet([run('Email-delivery customers’ invoices intended for print were switched to '),run('Print Later',{bold:true}),run(' before saving.')]));
children.push(body('Common Mistakes',{bold:true,color:C.greenDark}));
children.push(new Table({width:{size:100,type:WidthType.PERCENTAGE},rows:[new TableRow({children:[hCell('Don’t',50,null,C.red),hCell('Do',50)]}),
  ddRow('Print from the open invoice window for routine printing.','Batch-print from File > Print Forms > Invoices.',false),
  ddRow('Print to PDF to clear the print queue.','Print to the actual printer from the queue.',true),
  ddRow('Leave Print Later unchecked when creating the invoice.','Check Print Later at invoice creation.',false)]}));

children.push(sectionTitle('Key Performance Indicators'));
children.push(new Table({width:{size:100,type:WidthType.PERCENTAGE},rows:[new TableRow({children:[hCell('Indicator',52),hCell('Target',28),hCell('Owner',20)]}),
  kpiRow('Invoices with Print Later still checked older than 7 days','0','Office',false),
  kpiRow('Issued invoices marked printed in QuickBooks within 1 business day','100%','Office',true),
  kpiRow('Duplicate or re-sent invoices attributable to unclear print status per month','0','Office',false)]}));

children.push(sectionTitle('Related SOPs'));
children.push(bullet([run('OFF-QBS-INV-001',{bold:true}),run(' (parent) — Office QuickBooks Invoicing')]));
children.push(bullet([run('OFF-QBS-INV-002',{bold:true}),run(' (sibling) — Creating Customer Invoices in QuickBooks Desktop')]));
children.push(bullet([run('GOV-SOP-001',{bold:true}),run(' (governance) — SOP Authoring Standard')]));

const doc=new Document({creator:'Brookstone Operations System',title:SOP_CODE+' - '+SOP_TITLE,styles:{default:{document:{run:{font:'Calibri',size:21,color:C.gray}}}},sections:[{properties:{page:{margin:{top:1000,bottom:1000,left:1000,right:1000}}},children}]});
const outPath=path.join(__dirname,SOP_CODE+'_v'+SOP_VERSION+'.docx');
Packer.toBuffer(doc).then(b=>{fs.writeFileSync(outPath,b);console.log('Wrote '+outPath+' ('+b.length+' bytes)');}).catch(e=>{console.error('docx generation failed:',e);process.exit(1);});
