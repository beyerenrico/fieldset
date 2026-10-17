# 0044: The canvas builder (v2) is the default edit target
- Status: accepted (amends ADR 0037 "Page and switching")
- Date: 2026-10-22

## Context
ADR 0037 shipped the canvas builder (v2) next to the classic Filament Builder (v1) and kept v1 as the default from
the forms list. QUESTIONS #8 left the default open for M8. Both builders edit the same `forms.draft_definition`,
use the same SaveDraft/DefinitionChecker/PublishForm actions and guard each other's saves with `baseChecksum`.

Measured on the dev stack (Docker image of this branch, seeded service report, designer session, `curl`, two runs):

| Page | HTML | gzip | Server time |
|---|---|---|---|
| Classic builder `forms/1/edit` | 4.56 MB | 337 kB | 0.88–1.29 s |
| Canvas builder `forms/1/canvas` | 138 kB | 28 kB | 0.06–0.07 s |

The classic page renders every block schema of the Filament Builder (nested block pickers, logic and calculation
editors) into Livewire HTML and state (QUESTIONS #8). Its size grows with the form, and every Livewire round trip
carries that state.

Requirements this touches:
- §11 Performance: admin pages p95 under 500 ms. The classic builder alone takes 0.9–1.3 s on the server for the
  seeded service report, before the browser parses 4.5 MB. The canvas page is about 15× faster and 33× smaller.
- §11 Accessibility: "The builder v2 must be keyboard-operable." v2 was designed for it (ADR 0037: roving focus,
  keyboard pick-up and move, a Move menu as the single-pointer alternative to dragging, a live region). The
  M7 and M8 E2E suites build the §7.2 example with the keyboard only and walk its tab order (m7-builder-v2,
  m8-accessibility). v1 reorders with Filament's drag handles (`reorderable()`, not `reorderableWithButtons()`),
  so moving an element there needs a pointer, and Filament reorders only within one list.
- Parity: both builders edit the same JSON. The classic builder still offers what v2 does not show: the PDF
  import report with its low-confidence markers (§8.13), the versions table with rollback and the access
  settings (groups and roles).

## Decision
- **Default:** a row click and the "Builder" row action of the forms list open the canvas builder
  (`FormResource::builderUrl()`); so do new forms (blank, from a template, JSON import) and the resource's global
  search result.
- **Exception:** a form with an undismissed PDF import report (`forms.import_report`) opens in the classic builder,
  because only that builder shows the report and its markers. Dismissing the report there makes the canvas the
  default for that form too. The "PDF import finished" notification keeps linking to the classic builder.
- **Classic stays reachable:** a "Classic builder" row action in the list, the existing "Classic builder" toolbar
  button in v2 (flushes pending saves first) and the "Canvas builder" header action in v1 (saves first). The
  classic route `forms/{record}/edit` is unchanged, so bookmarks and the versions/rollback table keep working.
- Dashboard widgets (draft forms, recently published) keep linking to `edit`: their cached rows carry no import-report
  flag. They can follow once the widget data does.
- Both builders keep working and are both covered: E2E m1/m2/m4-versions use the classic builder explicitly
  (toolbar button or the `edit` URL), m7-builder-v2 and m8-csp use the canvas, and Pest covers the default URLs
  (`BuilderDefaultTest`).

## Consequences
- Designers land on the fast, keyboard-operable page by default; the 4.5 MB page loads only on request.
- Two editors stay in the product until the classic builder's remaining features (import report, versions table,
  access settings) exist in v2 or move to their own pages. Then the classic builder can be retired in a later ADR.
- Users who prefer the classic builder need one extra click per form; nothing remembers the choice.
