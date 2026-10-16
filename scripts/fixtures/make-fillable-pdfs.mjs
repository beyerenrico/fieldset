// Generates the fillable test PDFs for the PDF importer (BUILD_PROMPT §8.13, ADR 0024).
// Usage: node scripts/fixtures/make-fillable-pdfs.mjs   (writes into tests/Fixtures/pdf/)
// pdf-lib is MIT licensed. Every fixture is deterministic (fixed dates, no random ids).
// Encrypted variants are produced with qpdf (scripts/qpdf), then the qpdf JSON dumps used by the
// unit tests are refreshed with scripts/fixtures/dump-qpdf-json.php.
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PDFDocument, PDFHexString, PDFName, PDFString, StandardFonts } from 'pdf-lib';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const out = join(root, 'tests/Fixtures/pdf');
const qpdf = join(root, 'scripts/qpdf');
const FIXED_DATE = new Date('2026-10-16T08:00:00Z');
const A4 = [595.28, 841.89];

mkdirSync(out, { recursive: true });

async function newDocument(title) {
  const doc = await PDFDocument.create({ updateMetadata: false });
  doc.setTitle(title);
  doc.setCreationDate(FIXED_DATE);
  doc.setModificationDate(FIXED_DATE);
  doc.setProducer('form-schema-app fixtures');
  const font = await doc.embedFont(StandardFonts.Helvetica);

  return { doc, font, form: doc.getForm() };
}

const js = (doc, code) => doc.context.obj({ Type: 'Action', S: 'JavaScript', JS: PDFString.of(code) });
const jsHex = (doc, code) => doc.context.obj({ Type: 'Action', S: 'JavaScript', JS: PDFHexString.fromText(code) });
const jsStream = (doc, code) => doc.context.obj({ Type: 'Action', S: 'JavaScript', JS: doc.context.register(doc.context.flateStream(code)) });

function tooltip(field, text) {
  field.acroField.dict.set(PDFName.of('TU'), PDFHexString.fromText(text));
}

function actions(doc, field, map) {
  field.acroField.dict.set(PDFName.of('AA'), doc.context.obj(map));
}

function label(page, font, text, x, y, size = 10) {
  page.drawText(text, { x, y, size, font });
}

const moneyFormat = (doc) => ({
  F: jsHex(doc, 'AFNumber_Format(2, 0, 0, 0, "€", false);'),
  K: jsHex(doc, 'AFNumber_Keystroke(2, 0, 0, 0, "€", false);'),
});

/** Signature fields have no pdf-lib API: build the merged field/widget dictionary by hand. */
function signatureField(doc, page, name, rect, tip) {
  const ref = doc.context.register(doc.context.obj({
    FT: 'Sig', T: PDFString.of(name), TU: PDFHexString.fromText(tip),
    Type: 'Annot', Subtype: 'Widget', Rect: rect, F: 4, P: page.ref,
  }));
  page.node.addAnnot(ref);
  doc.getForm().acroForm.addField(ref);
}

/** (a) Service sheet: every row of the §8.13 mapping table, an 8-row table and pre-filled values. */
async function serviceSheet() {
  const { doc, font, form } = await newDocument('Service sheet');
  const p1 = doc.addPage(A4);
  const p2 = doc.addPage(A4);

  label(p1, font, 'Service sheet', 50, 790, 18);

  const customer = form.createTextField('CustomerName');
  tooltip(customer, 'Customer');
  customer.enableRequired();
  customer.addToPage(p1, { x: 160, y: 740, width: 300, height: 20, font });
  customer.setText('Erika Mustermann');

  label(p1, font, 'Service date:', 50, 706);
  const date = form.createTextField('ServiceDate');
  actions(doc, date, {
    F: js(doc, 'AFDate_FormatEx("dd.mm.yyyy");'),
    K: js(doc, 'AFDate_KeystrokeEx("dd.mm.yyyy");'),
  });
  date.addToPage(p1, { x: 160, y: 700, width: 120, height: 20, font });
  date.setText('16.10.2026');

  const phone = form.createTextField('Phone');
  tooltip(phone, 'Phone');
  actions(doc, phone, { F: js(doc, 'AFSpecial_Format(2);'), K: js(doc, 'AFSpecial_Keystroke(2);') });
  phone.addToPage(p1, { x: 340, y: 700, width: 150, height: 20, font });
  phone.setText('0301234567');

  const type = form.createDropdown('ServiceType');
  tooltip(type, 'Service type');
  type.enableRequired();
  type.addOptions(['Maintenance', 'Repair', 'Installation']);
  type.addToPage(p1, { x: 160, y: 660, width: 150, height: 20, font });
  type.select('Repair');

  const trades = form.createOptionList('Trades');
  tooltip(trades, 'Trades involved');
  trades.addOptions(['x']);
  trades.acroField.dict.set(PDFName.of('Opt'), doc.context.obj([
    [PDFString.of('el'), PDFString.of('Electrical')],
    [PDFString.of('pl'), PDFString.of('Plumbing')],
    [PDFString.of('hv'), PDFString.of('HVAC')],
  ]));
  trades.enableMultiselect();
  trades.addToPage(p1, { x: 160, y: 590, width: 150, height: 55, font });
  trades.acroField.dict.set(PDFName.of('V'), doc.context.obj([PDFString.of('el'), PDFString.of('hv')]));

  const payment = form.createRadioGroup('PaymentMethod');
  tooltip(payment, 'Payment method');
  payment.addOptionToPage('Cash', p1, { x: 160, y: 555, width: 14, height: 14 });
  payment.addOptionToPage('Invoice', p1, { x: 240, y: 555, width: 14, height: 14 });
  payment.select('Invoice');
  label(p1, font, 'Cash', 178, 557);
  label(p1, font, 'Invoice', 258, 557);

  const done = form.createCheckBox('WorkCompleted');
  tooltip(done, 'Work completed');
  done.addToPage(p1, { x: 160, y: 520, width: 14, height: 14 });
  done.check();

  const postcode = form.createTextField('postcode');
  postcode.setMaxLength(5);
  postcode.enableCombing();
  postcode.addToPage(p1, { x: 160, y: 480, width: 100, height: 20, font });
  postcode.setText('12345');

  const order = form.createTextField('OrderNo');
  tooltip(order, 'Order number');
  order.setMaxLength(12);
  order.addToPage(p1, { x: 340, y: 480, width: 150, height: 20, font });

  const notes = form.createTextField('Notes');
  tooltip(notes, 'Work notes');
  notes.enableMultiline();
  notes.addToPage(p1, { x: 160, y: 380, width: 330, height: 80, font });
  notes.setText('Customer mentioned a noisy pump.');

  const technician = form.createTextField('TechnicianId');
  tooltip(technician, 'Technician ID');
  technician.enableReadOnly();
  technician.addToPage(p1, { x: 160, y: 340, width: 120, height: 20, font });
  technician.setText('T-0042');

  const internal = form.createTextField('InternalRef');
  internal.addToPage(p1, { x: 340, y: 340, width: 120, height: 20, font, hidden: true });
  internal.setText('secret-ref');

  const print = form.createButton('PrintButton');
  print.addToPage('Print', p1, { x: 400, y: 790, width: 80, height: 20, font });
  print.acroField.dict.set(PDFName.of('AA'), doc.context.obj({ U: js(doc, 'this.print();') }));

  // Page 2: the materials table (8 fixed rows) with column headers above the first row.
  label(p2, font, 'Materials', 50, 790, 14);
  const columns = { Desc: [50, 220], Qty: [280, 60], Price: [350, 90], Total: [450, 90] };
  label(p2, font, 'Description', 50, 762);
  label(p2, font, 'Qty', 280, 762);
  label(p2, font, 'Unit price', 350, 762);
  label(p2, font, 'Total', 450, 762);
  const calcOrder = [];
  for (let row = 1; row <= 8; row++) {
    const y = 760 - row * 26;
    for (const [column, [x, width]] of Object.entries(columns)) {
      const field = form.createTextField(`Row${row}${column}`);
      field.addToPage(p2, { x, y, width, height: 20, font });
      if (column === 'Qty') {
        actions(doc, field, { F: js(doc, 'AFNumber_Format(2, 1, 0, 0, "", false);'), K: js(doc, 'AFNumber_Keystroke(2, 1, 0, 0, "", false);') });
      } else if (column === 'Price') {
        actions(doc, field, moneyFormat(doc));
      } else if (column === 'Total') {
        field.enableReadOnly();
        actions(doc, field, {
          ...moneyFormat(doc),
          C: js(doc, `AFSimple_Calculate("PRD", new Array ("Row${row}Qty", "Row${row}Price"));`),
        });
        calcOrder.push(field.acroField.ref);
      }
    }
  }
  const prefill = { Row1Desc: 'Pump seal', Row1Qty: '2.00', Row1Price: '12,50 €', Row1Total: '25,00 €' };
  for (const [name, value] of Object.entries(prefill)) {
    form.getTextField(name).setText(value);
  }

  const totals = [
    ['NetTotal', 'Net total', `AFSimple_Calculate("SUM", new Array ("Row1Total", "Row2Total", "Row3Total", "Row4Total", "Row5Total", "Row6Total", "Row7Total", "Row8Total"));`, 520],
    ['AvgPrice', 'Average unit price', 'AFSimple_Calculate("AVG", new Array ("Row1Price", "Row2Price", "Row3Price", "Row4Price", "Row5Price", "Row6Price", "Row7Price", "Row8Price"));', 490],
    ['MaxQty', 'Largest quantity', 'AFSimple_Calculate("MAX", new Array ("Row1Qty", "Row2Qty", "Row3Qty", "Row4Qty", "Row5Qty", "Row6Qty", "Row7Qty", "Row8Qty"));', 460],
  ];
  for (const [name, tip, script, y] of totals) {
    const field = form.createTextField(name);
    tooltip(field, tip);
    field.enableReadOnly();
    const format = name === 'MaxQty'
      ? { F: js(doc, 'AFNumber_Format(2, 1, 0, 0, "", false);') }
      : moneyFormat(doc);
    // NetTotal stores its calculation script as a stream, as Acrobat does for longer scripts.
    actions(doc, field, { ...format, C: name === 'NetTotal' ? jsStream(doc, script) : js(doc, script) });
    field.addToPage(p2, { x: 450, y, width: 90, height: 20, font });
    calcOrder.push(field.acroField.ref);
  }
  form.getTextField('NetTotal').setText('25,00 €');

  const travel = form.createTextField('Travel');
  tooltip(travel, 'Travel costs');
  actions(doc, travel, moneyFormat(doc));
  travel.addToPage(p2, { x: 450, y: 430, width: 90, height: 20, font });

  const grand = form.createTextField('GrandTotal');
  tooltip(grand, 'Grand total');
  grand.enableReadOnly();
  actions(doc, grand, { ...moneyFormat(doc), C: js(doc, 'AFSimple_Calculate("SUM", new Array ("NetTotal", "Travel"));') });
  grand.addToPage(p2, { x: 450, y: 400, width: 90, height: 20, font });
  calcOrder.push(grand.acroField.ref);

  const discount = form.createTextField('Discount');
  tooltip(discount, 'Loyalty discount');
  discount.enableReadOnly();
  actions(doc, discount, { C: js(doc, 'event.value = this.getField("NetTotal").value * 0.1;') });
  discount.addToPage(p2, { x: 450, y: 370, width: 90, height: 20, font });
  calcOrder.push(discount.acroField.ref);

  signatureField(doc, p2, 'CustomerSignature', [50, 250, 300, 310], 'Customer signature');

  form.acroForm.dict.set(PDFName.of('CO'), doc.context.obj(calcOrder));
  form.acroForm.dict.set(PDFName.of('NeedAppearances'), doc.context.obj(true));

  return doc.save({ useObjectStreams: false });
}

/** (b) Hierarchical names: items.N.desc/qty/price/total (5 rows) and a single-column notes.N list. */
async function hierarchical() {
  const { doc, font, form } = await newDocument('Delivery note');
  const page = doc.addPage(A4);
  label(page, font, 'Delivery note', 50, 790, 18);
  label(page, font, 'Description', 50, 752);
  label(page, font, 'Qty', 280, 752);
  label(page, font, 'Price', 350, 752);
  label(page, font, 'Total', 450, 752);
  const columns = { desc: [50, 220], qty: [280, 60], price: [350, 90], total: [450, 90] };
  for (let row = 0; row < 5; row++) {
    const y = 724 - row * 26;
    for (const [column, [x, width]] of Object.entries(columns)) {
      const field = form.createTextField(`items.${row}.${column}`);
      field.addToPage(page, { x, y, width, height: 20, font });
      if (column === 'total') {
        field.enableReadOnly();
        actions(doc, field, {
          ...moneyFormat(doc),
          C: js(doc, `AFSimple_Calculate("PRD", new Array ("items.${row}.qty", "items.${row}.price"));`),
        });
      } else if (column === 'price') {
        actions(doc, field, moneyFormat(doc));
      }
    }
  }
  const sum = form.createTextField('sum');
  tooltip(sum, 'Sum');
  sum.enableReadOnly();
  actions(doc, sum, { ...moneyFormat(doc), C: js(doc, 'AFSimple_Calculate("SUM", "items.0.total, items.1.total, items.2.total, items.3.total, items.4.total");') });
  sum.addToPage(page, { x: 450, y: 580, width: 90, height: 20, font });

  const cheapest = form.createTextField('cheapest');
  tooltip(cheapest, 'Cheapest price');
  cheapest.enableReadOnly();
  actions(doc, cheapest, { ...moneyFormat(doc), C: js(doc, 'AFSimple_Calculate("MIN", ["items.0.price", "items.1.price", "items.2.price", "items.3.price", "items.4.price"]);') });
  cheapest.addToPage(page, { x: 450, y: 550, width: 90, height: 20, font });

  label(page, font, 'Remarks', 50, 520);
  for (let row = 0; row < 4; row++) {
    form.createTextField(`notes.${row}`).addToPage(page, { x: 50, y: 492 - row * 26, width: 400, height: 20, font });
  }

  return doc.save({ useObjectStreams: false });
}

/** (d) 51 small pages: over the 50-page limit while staying tiny on disk. */
async function tooManyPages() {
  const { doc, font, form } = await newDocument('Too many pages');
  for (let i = 1; i <= 51; i++) {
    const page = doc.addPage([200, 200]);
    label(page, font, `Page ${i}`, 20, 170);
  }
  form.createTextField('only_field').addToPage(doc.getPage(0), { x: 20, y: 100, width: 120, height: 20, font });

  return doc.save({ useObjectStreams: false });
}

/** A PDF without any form fields. */
async function noForm() {
  const { doc, font } = await newDocument('Flat');
  label(doc.addPage(A4), font, 'This PDF has no form fields.', 50, 790);

  return doc.save({ useObjectStreams: false });
}

writeFileSync(join(out, 'service-sheet.pdf'), await serviceSheet());
writeFileSync(join(out, 'hierarchical.pdf'), await hierarchical());
writeFileSync(join(out, 'too-many-pages.pdf'), await tooManyPages());
writeFileSync(join(out, 'no-form.pdf'), await noForm());
// (c) Malformed: a PDF header followed by garbage, no objects, no xref table.
writeFileSync(join(out, 'malformed.pdf'), '%PDF-1.7\n%\xE2\xE3\xCF\xD3\nthis is not a pdf body\n%%EOF\n', 'latin1');

// Encrypted variants (qpdf): one needs a user password, one only restricts editing (owner password).
const run = (args) => execFileSync(qpdf, args, { stdio: 'inherit' });
run(['--encrypt', 'user-secret', 'owner-secret', '256', '--', join(out, 'hierarchical.pdf'), join(out, 'encrypted-user.pdf')]);
run(['--encrypt', '', 'owner-secret', '256', '--', join(out, 'hierarchical.pdf'), join(out, 'encrypted-owner.pdf')]);

execFileSync('php', [join(root, 'scripts/fixtures/dump-qpdf-json.php')], { stdio: 'inherit' });
console.log(`fixtures written to ${out}`);
