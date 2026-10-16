{{-- Print CSS (paged media). Content flows across pages and is never clipped: no fixed heights, no overflow hidden. --}}
:root { --primary: {{ $primary }}; --accent: {{ $accent }}; }
* { box-sizing: border-box; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { margin: 0; font-family: {!! $fontFamily !!}; font-size: 10pt; line-height: 1.4; color: #111827; }
.layout-compact { font-size: 8.5pt; line-height: 1.3; }
h1, h2, h3 { margin: 0; font-weight: 700; break-after: avoid; }
.doc-title { border-left: 1.2mm solid var(--primary); padding: 1mm 0 1mm 4mm; margin-bottom: 6mm; break-inside: avoid; }
.doc-title h1 { font-size: 16pt; color: var(--primary); }
.layout-compact .doc-title h1 { font-size: 13pt; }
.doc-meta { display: flex; flex-wrap: wrap; gap: 1mm 8mm; margin: 2mm 0 0; }
.doc-meta div { display: flex; gap: 2mm; }
.doc-meta dt { color: #6b7280; }
.doc-meta dd { margin: 0; font-weight: 700; }
.page-title { font-size: 12pt; color: var(--primary); margin: 5mm 0 2mm; padding-bottom: 1mm; border-bottom: 0.3mm solid var(--accent); }
.section-title { font-size: 10.5pt; color: var(--accent); margin: 3mm 0 1.5mm; }
.fields { display: flex; flex-wrap: wrap; align-items: flex-start; margin: 0 -2mm; }
.node { padding: 1mm 2mm; width: 100%; overflow-wrap: anywhere; word-break: break-word; }
.w-half { width: 50%; }
.w-third { width: 33.333%; }
.keep, .field, .photo, .card, .signature, tr { break-inside: avoid; }
.label { color: #4b5563; font-size: 0.85em; }
.value { white-space: pre-wrap; }
.value.multiline { white-space: pre-wrap; }
.layout-two_column .field.w-full { display: grid; grid-template-columns: 35% 1fr; gap: 4mm; border-bottom: 0.2mm solid #e5e7eb; padding-top: 1.5mm; padding-bottom: 1.5mm; }
.layout-two_column .field.w-full .label { font-size: 1em; }
.layout-compact .field { display: flex; gap: 2mm; padding-top: 0.5mm; padding-bottom: 0.5mm; }
.layout-compact .field .label::after { content: ":"; }
.layout-compact .field .label { font-size: 1em; white-space: nowrap; }
.section { margin-top: 1mm; }
.columns { display: grid; gap: 0 4mm; }
.columns .column { margin: 0; }
.repeater { margin: 1mm 0 2mm; }
table.rows { width: 100%; border-collapse: collapse; table-layout: auto; }
table.rows thead { display: table-header-group; }
table.rows th { background: var(--primary); color: #fff; text-align: left; font-weight: 700; padding: 1.2mm 1.5mm; font-size: 0.9em; }
table.rows td { padding: 1mm 1.5mm; border-bottom: 0.2mm solid #e5e7eb; vertical-align: top; overflow-wrap: anywhere; white-space: pre-wrap; }
table.rows tbody tr:nth-child(even) td { background: #f9fafb; }
table.rows .numeric { text-align: right; white-space: nowrap; }
table.rows .index { width: 8mm; color: #6b7280; text-align: right; }
.layout-compact table.rows td, .layout-compact table.rows th { padding: 0.6mm 1mm; }
.card { border: 0.3mm solid #d1d5db; border-radius: 1.5mm; padding: 2mm 3mm; margin: 0 0 2mm; }
.card-title { font-weight: 700; color: var(--accent); margin-bottom: 1mm; }
.photo-grid { display: grid; gap: 2mm; margin-top: 1mm; }
.photo { margin: 0; }
.photo img { display: block; width: 100%; height: auto; border-radius: 1mm; }
.image { margin: 1mm 0; }
.image img { max-width: 100%; height: auto; }
.heading { font-weight: 700; color: var(--primary); margin-top: 2mm; }
.heading.level-1 { font-size: 14pt; }
.heading.level-2 { font-size: 12pt; }
.heading.level-3 { font-size: 10.5pt; }
.paragraph p { margin: 0 0 1.5mm; }
.divider { border: 0; border-top: 0.3mm solid #d1d5db; margin: 2mm 0; padding: 0; }
.page-break { break-after: page; height: 0; padding: 0; }
.signature-block { display: flex; flex-wrap: wrap; margin: 8mm -2mm 0; break-inside: avoid; }
.signature.w-full { max-width: 110mm; }
.signature-box { height: 27mm; padding-bottom: 1mm; border-bottom: 0.3mm solid #111827; display: flex; align-items: flex-end; }
.signature-box img { max-height: 25mm; max-width: 100%; }
.signer { font-size: 0.85em; color: #4b5563; margin-top: 1mm; }
.numeric .value { font-variant-numeric: tabular-nums; }
.watermark { position: fixed; top: 40%; left: 0; right: 0; text-align: center; font-size: 96pt; font-weight: 700; color: rgba(220, 38, 38, 0.12); transform: rotate(-30deg); z-index: 1000; pointer-events: none; }
