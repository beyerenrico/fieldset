# 0024: Fillable PDF import reads AcroForms with the qpdf CLI (not pdf.js)
- Status: accepted
- Date: 2026-10-16

## Context
§8.13 imports a fillable PDF as a draft definition, deterministically and without AI, in the queue
worker. The importer needs, per field: full name, type, flags (required, read-only, multiline, comb,
radio, push button, combo, multi-select), tooltip (/TU), /MaxLen, choice options (/Opt), radio
export values, widget page and rectangle, annotation flags (hidden) and the Acrobat format,
keystroke and calculation scripts (/AA /F /K /C) as text. Labels may also come from nearby page text.
Two candidates: qpdf's JSON output, or pdf.js `getFieldObjects()` / `getCalculationOrderIds()`.

Verified with qpdf 11.3.0 (image `form-schema-app-ci`, `qpdf --json-help`): `--json=2` with
`--json-key=acroform` lists every widget with `fullname`, `fieldtype`, `fieldflags` (inherited),
`alternativename`, `pageposfrom1` and `annotation.annotationflags`. `alternativename` falls back to
the full name, and qpdf reads it (and `choices`) from the widget, so pdf-lib/Acrobat fields whose
/TU and /Opt sit on the parent field dictionary come back without them. `--json-key=qpdf` adds the
object table (strings `u:`/`b:`, names `/X`, references `N G R`), which has everything else.

## Decision
- Use the qpdf CLI already installed in the app and CI images (`docker/app/Dockerfile`,
  `docker/ci/Dockerfile`), behind the `AcroFormReader` interface (`QpdfAcroFormReader`).
- Two calls per file, each with a timeout (`forms.pdf_import.timeout`) and an output cap:
  1. `qpdf --json=2 --json-key=acroform --json-key=pages --json-key=encrypt --json-key=qpdf
     --json-stream-data=none <file>` — field list, pages, encryption and objects without streams.
  2. `qpdf --json=2 --json-key=qpdf --json-stream-data=inline --decode-level=generalized
     --json-object=N,G … <file>` — only page content streams (label detection) and JavaScript
     action streams.
- Tooltip, options, /MaxLen and actions are read from the terminal field dictionary (nearest
  dictionary with /T in the widget's /Parent chain); inheritable keys walk the chain.
- JavaScript is never executed. Format and calculation scripts are matched as text against
  Acrobat's built-ins (`AFNumber_*`, `AFPercent_*`, `AFDate_*`, `AFTime_*`, `AFSpecial_*`,
  `AFSimple_Calculate("SUM|PRD|AVG|MIN|MAX", …)`); anything else is custom and reported.
- Field values (/V) are never read into the model, so pre-filled sample data cannot be imported.
- Limits before and during parsing: ≤ 20 MB (`forms.pdf_import.max_bytes`), ≤ 50 pages
  (`max_pages`), encrypted files (qpdf "invalid password" or `encrypt.encrypted`) and qpdf exit
  code 2 are rejected with a translated error; exit code 3 (repaired) imports with a warning.
- The binary path comes from `forms.qpdf_binary` (`QPDF_BINARY`, relative paths resolve against the
  project root). `scripts/qpdf` runs a local qpdf if present, else `docker run --rm --network none`
  of the CI image with each file's directory mounted; the test suite uses it, so PDF tests always
  run on hosts without qpdf.

## Consequences
- No Node runtime in the PHP queue worker and no headless browser; qpdf is Apache-2.0 and already
  shipped. pdf.js would add a Node sidecar to the worker and evaluate its own field model.
- qpdf exposes no calculation order; the importer does not need it, because our evaluator orders
  calculations by dependency (§7.4).
- Label detection from page text is best effort: simple-font text is decoded as WinAnsi with
  estimated glyph widths; composite (Type0) fonts and text inside form XObjects are ignored.
- The importer depends on qpdf JSON v2; a future qpdf with JSON v3 needs a parser update (the
  version is pinned by `--json=2`).
