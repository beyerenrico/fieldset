# 0037: Builder v2 (canvas builder: palette, canvas, inspector)
- Status: accepted (default edit target amended by ADR 0044)
- Date: 2026-10-18

## Context
§8.2 asks for a custom Filament page with a palette, a canvas and an inspector, built on Livewire, Alpine and SortableJS. It needs keyboard alternatives and undo/redo, and it edits the same canonical JSON as v1, so no data migration is needed.

§11 asks for a keyboard-operable builder, WCAG 2.2 AA, and a 300-element form that stays responsive. SC 2.5.7 requires a single-pointer alternative to dragging.

The v1 problems this design avoids:
- v1 keeps Filament Builder state in Livewire, and its service-report editor renders about 4 MB of HTML (QUESTIONS #8).
- v1 re-creates the preview iframe on every re-render.

The canonical JSON is small: the service report is 4.2 kB minified.

Spec 1.0 allows any element inside any container, including a repeater inside a repeater. v1 adds a cap: no containers at depth 3.

Versions (npm, checked 2026-10-18):
- sortablejs 1.15.7 (MIT, published 2026-02-11), @types/sortablejs 1.15.9;
- @axe-core/playwright 4.13.0 (MPL-2.0, 2026-08-11). 4.14.0 was only two days old and was skipped.

Filament 5.10 and Livewire 4 each bundle their own Sortable. We use neither.

## Decision
### Page and switching
- `CanvasBuilder` (a resource page `forms/{record}/canvas`) edits `forms.draft_definition`. It uses the same SaveDraft, DefinitionChecker and PublishForm as v1, so there is no migration.
- Navigation:
  - v1 has a "Canvas builder" action that saves first.
  - v2 has a "Classic builder" button that flushes pending saves first.
  - The forms list keeps v1 as the default and adds a "Canvas builder" record action. (Superseded by ADR 0044: the canvas is the default, the list offers "Classic builder".)
- Every v2 save sends `baseChecksum`, the checksum the server last acknowledged. A mismatch returns `conflict`, and the user chooses reload or overwrite. v1 autosave gets the same guard, so a stale tab never silently overwrites the other builder's work.

### State: the browser holds the working copy, the server validates whole definitions
- The working copy is the canonical definition in a TypeScript store (`resources/builder-v2`). It is embedded once at mount and is not a Livewire property.
- Livewire state stays small: the inspected node and its scope.
- Operations (`insert`, `move`, `remove`, `update`, `addColumn`, `removeColumn`) are pure functions with structural sharing. They never touch unknown or `x-*` properties.
- `sync()` uses `#[Json]` and does not re-render. It validates the whole definition and saves it as an autosave, which is not audited. `save()` is the explicit, audited save.
  - Structurally invalid definitions are refused and stay in the browser.
  - Lint findings are returned and block publishing.
- The server does not apply operations. The client needs the tree logic anyway to render instantly, and a tampered client can only submit a definition, which is checked like an import.
- Autosave: debounce 800 ms, at most 5 s between saves, one request in flight, the latest state wins. It is flushed before publish, Preview PDF, export, switching builders and unload.
- Publish, Preview PDF and Export are page actions (`publishAction()`, etc.). They are mounted with `{checksum}` and refuse a checksum that differs from the stored draft. PublishForm re-lints in its transaction.
- Preview: the same `/form-preview/` iframe and protocol as v1, fed from the store at most every 250 ms. The iframe is never re-created.
- Undo/redo: up to 100 definition snapshots, each with a label and the selection. Inspector edits to the same node within 1 s merge into one entry.
  - Shortcuts: Ctrl/Cmd+Z and Ctrl/Cmd+Shift+Z or Ctrl+Y, except inside text inputs.
  - History is not persisted.

### Palette, canvas, inspector
- **Palette:** items are buttons. Click or Enter inserts after the selection. They are also SortableJS clone sources.
- **Canvas:** a small keyed DOM renderer under one `wire:ignore` root, so Livewire never morphs it.
  - SortableJS 1.15.7 is our own import, with `forceFallback: true`, drag handles and empty-list thresholds.
  - In `onEnd`, SortableJS's DOM move is reverted, an operation is dispatched, and the canvas re-renders.
- **Nesting rules:**
  - Pages go only into the page list.
  - Elements never go into themselves or their descendants.
  - A list at depth 3 accepts no containers (v1 parity).
  - `columns` keep 2–4 columns; removing a non-empty column moves its elements to the neighbouring column.
  - Moving a published element into or out of a repeater asks for confirmation, because its data path changes.
- **Keyboard:** every card has a handle.
  - Space or Enter picks the card up. Arrow keys move it within and across lists, → moves it into the previous container, ← moves it out, and PageUp/PageDown move it between pages. Space or Enter drops it; Esc cancels.
  - Every card also has a "Move" menu, the single-pointer alternative.
  - Focus follows the moved card.
  - One polite live region announces every move, insert, delete, undo and refusal.
- **Inspector:** a Filament schema on the page that reuses v1's element fields, the settings, LogicEditor, CalculationEditor and KeySuggester.
  - `inspect()` loads a node without its children; `commitInspector()` validates and dispatches `builder-v2:node-changed`. The client applies an `update`, and the next sync saves it.
  - The inspector never writes to the database.
- **Cloning** (`cloneNode`): fresh UUIDv7 ids, keys made unique in the target scope, references rewritten.
- **Locked keys:** keys of ids published in a version are locked. Cards show a lock, and the linter's `key_locked` is the safety net.

### Contract
`resources/builder-v2/src/contract.ts` defines the contract, with example payloads in `tests/Fixtures/builder-v2/contract/`. Both are orchestrator-owned.
- JSON travels as strings, because Livewire would turn `{}` into `[]` (ADR 0022).
- E2E uses the data attributes `data-builder-*`.

### Assets
- The npm workspace `resources/builder-v2` has strict TS, Vitest (jsdom), ESLint and Prettier.
- The root Vite builds `src/main.ts` and `src/builder.css`.
- The page loads the script through async-alpine (`x-load-src` with `Vite::asset`) and the CSS with `@vite`.
- CSS is plain, with the `bv2-` prefix and Filament's CSS variables, because no theme compiles Tailwind for our views (LEARNINGS #42).

### Performance targets
- Canvas page HTML for the service report under 500 kB.
- Builder JS at most 60 kB gzipped.
- An operation updates the canvas within 50 ms p95 at 300 elements.
- The preview updates within 300 ms.
- `sync` p95 at most 500 ms including lint.
- The inspector opens within 400 ms.

### Testing
- Pest/Livewire, with `withoutVite()`:
  - sync statuses: saved, unchanged, invalid, conflict;
  - audit on save, and the checksum and lint guards on the actions;
  - inspect/commit;
  - cloneNode;
  - lossless NodeMapper round trips over all fixtures;
  - HTML size.
- Vitest:
  - operations: no mutation, the canDrop matrix, inverse pairs;
  - history, keyboard, the sync scheduler, renderer ARIA and focus.
- Playwright:
  - the §7.2 example built by drag (mouse steps) and by keyboard only;
  - a v1 ↔ v2 round trip;
  - axe checks.

## Consequences
- v1 stays; M8 decides whether to retire it.
- The TypeScript client is a second implementation of tree manipulation. It is checked by server validation and fidelity tests.
- ElementBlocks, BuilderMapper, BlockCloner and EditForm get small refactors that v1's tests keep green.
- Three copies of Sortable end up on the page, about 15 kB gzipped.

## Amendment 2026-10-18: asset loading
Vite app builds drop the entry's exports (`preserveEntrySignatures: false`), so async-alpine's plain `x-load-src` would register `false`.
- The page therefore keeps `x-load` and registers the component in a small inline script on `alpine:initializing`: `Alpine.asyncData('builderV2', () => import(<Vite asset>).then(m => m.default ?? window.FormSchemaBuilderV2))`.
- main.ts also sets `window.FormSchemaBuilderV2`.
- The root content and `builderConfig()` render only on the first request; Livewire never morphs the `wire:ignore` root.
- Bundle: 31.8 kB gzipped.
