# @form-schema-app/form-schema-react

The one React renderer for **Form Schema Spec 1.0** definitions. The Field App and the builder
preview both use it. All form semantics (visibility, defaults, calculated values, validation) come
from `evaluate()` in [`@form-schema-app/form-schema-js`](../form-schema-js); the renderer only
draws the result and collects input. It makes no network calls: files go through an injected
adapter.

## Usage

```tsx
import { FormRenderer, createMemoryAttachmentAdapter } from '@form-schema-app/form-schema-react';
import '@form-schema-app/form-schema-react/styles.css';

const attachments = createMemoryAttachmentAdapter(); // or your API/IndexedDB adapter

function Fill({ definition }) {
    const [data, setData] = useState({});
    const [showErrors, setShowErrors] = useState(false);
    return (
        <FormRenderer
            definition={definition}
            data={data}
            onChange={(next) => setData(next)}
            context={{ today: '2026-10-16', now: '2026-10-16T09:41:00+02:00', user: { name: 'Willi' } }}
            attachments={attachments}
            showErrors={showErrors}
            primaryAction={{ label: 'Review', onClick: () => setShowErrors(true) }}
            uiLocale="de"
        />
    );
}
```

| Prop | Purpose |
| --- | --- |
| `definition` | Raw definition JSON or a `parseDefinition` model |
| `data`, `onChange(data, result)` | Controlled submission data. `onChange` receives user input plus applied defaults (calculated values are not copied; the server recomputes them) |
| `context` | `evaluate()` built-ins: `today`, `now`, `user`, `org`, `formVersion` |
| `attachments` | `AttachmentAdapter` (required) |
| `errors` | Extra errors by JSON Pointer, e.g. a server `validation_failed` response; always shown, `message` wins over the code text |
| `showErrors` | Show every client error plus the error summary (after a submit attempt) |
| `mode` | `fill` (default) or `preview` (shows a "not saved" banner) |
| `layout` | `wizard` or `scroll`; overrides `settings.layout` |
| `readOnly`, `readOnlyExcept` | Disable inputs, optionally keeping some types editable (e.g. `['signature']` on a review screen) |
| `primaryAction` | `{ label, onClick, disabled?, busy? }` in the sticky footer (wizard: last page only) |
| `uiLocale`, `messages` | Language of the renderer's own texts (DE/EN) and overrides. Numbers, dates and currency use the definition `locale` |
| `onEvaluate`, `onPageChange`, `idPrefix`, `className` | Hooks for hosts |

Other exports: `useFormEvaluation`, `materializeDefaults`, `compressImage`, `errorMessage`,
`resolveMessages`, `rendererMessages`, `parseDecimalInput`, `getAt`/`setAt`/`toPointer`,
`formatDecimal`/`formatMoney` and the public types.

## Attachment adapter

```ts
interface AttachmentAdapter {
    upload(request: { file: Blob; fileName: string; kind: 'photo' | 'file' | 'signature'; elementKey: string; pointer: string }): Promise<{ id: string; fileName?: string; mime?: string; size?: number; url?: string }>;
    get?(id: string): AttachmentInfo | undefined; // describe attachments already in the data (thumbnails after reload)
    remove?(id: string, request): void | Promise<void>;
}
```

The returned `id` (a UUID) is written into the data. Photos are re-encoded on the device before
`upload` (long edge ≤ 2048 px, JPEG quality 0.85, which drops EXIF); images the browser cannot
decode (e.g. HEIC on some platforms) are passed through unchanged. Signatures are PNG. If
`upload` rejects with an error that has a non-empty `message`, that text is shown next to the field.

## Behavior notes

- Errors from `evaluate()` appear after a field was touched, after a blocked "Next" in the wizard,
  or with `showErrors`. The summary links reveal the field (switching wizard pages, scrolling
  virtualized repeater rows) and focus it.
- `number`/`money` inputs use `inputmode="decimal"`, accept `,` or `.` and store decimal strings.
- Repeaters with more than 25 rows are virtualized against the **window** scroll position, so the
  host page should scroll the window (not an inner container).
- `location` is only requested after the user presses the button.
- Paragraph Markdown is rendered with raw HTML and images disabled; links open in a new tab.
  `image` elements only render `data:image/png|jpeg|webp` URIs; `asset:` and remote sources show a
  placeholder.
- `readOnlyIf` is honored when it is the literal `true`; dynamic expressions need an SDK API that
  evaluates logic in an element's scope (not exported yet). The required marker for `requiredIf`
  is shown while `evaluate()` reports `required` for the empty field.

## Styling

Plain CSS with `fsr-` classes. Theme through custom properties on `.fsr-root`:
`--fsr-font-scale` (larger text, e.g. `1.25`), `--fsr-color-text`, `--fsr-color-muted`,
`--fsr-color-bg`, `--fsr-color-surface`, `--fsr-color-border`, `--fsr-color-primary`,
`--fsr-color-primary-contrast`, `--fsr-color-danger`, `--fsr-color-focus`, `--fsr-radius`,
`--fsr-gap`, `--fsr-control-height` (≥ 44 px touch targets). Dark tokens follow
`prefers-color-scheme`; pass `className="fsr-light"` or `"fsr-dark"` to force a scheme.

## Scripts

`npm test`, `npm run coverage` (threshold 80 % lines), `npm run lint`, `npm run typecheck`.

## Dependencies

| Package | Version | License | Use |
| --- | --- | --- | --- |
| `@form-schema-app/form-schema-js` | workspace | MIT | evaluate, model, types |
| `react`, `react-dom` | ^19 (peer) | MIT | UI |
| `@tanstack/react-virtual` | 3.14.21 | MIT | long repeaters |
| `signature_pad` | 5.1.4 | MIT | signature drawing |
| `markdown-it` (+ `@types/markdown-it` 14.2.0) | 14.3.3 | MIT | paragraph Markdown (14.x: 15.x pulls argparse 3 under PSF-2.0, which the license check rejects) |
| `vitest`, `@vitest/coverage-v8`, `jsdom` | 5.x, 30.1.3 | MIT | tests (dev) |
| `@testing-library/react`, `dom`, `user-event`, `jest-dom` | 16.3.3, 10.4.2, 14.6.8, 7.0.1 | MIT | tests (dev) |
| `eslint`, `typescript-eslint`, `eslint-plugin-react-hooks`, `globals` | 10.x, 8.x, 7.1.1, 17.13.0 | MIT | lint (dev) |
| `typescript` | ~6.0.3 | Apache-2.0 | dev |
