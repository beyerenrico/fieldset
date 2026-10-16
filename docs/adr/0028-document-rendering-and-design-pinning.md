# 0028: Document rendering pipeline and design-version pinning
- Status: accepted
- Date: 2026-10-16

## Context
§8.5 asks for app-owned Blade templates rendered by Gotenberg (Chromium) without network access,
immutable renditions with `input_hash` and `file_sha256`, designs versioned like forms, and: "re-rendering
an old submission after a design change still uses that submission's design version". The data model
(§6.4) has a nullable `form_versions.design_version_id`, but form versions are immutable and published by
the Forms module, and a design change should reach new documents without republishing every form.

## Decision
- **Pinning:** a submission's design version is the `design_version_id` of its **first rendition**
  (renditions are immutable, so the pin is too). `DesignResolver::forSubmission()` returns it; without a
  rendition it resolves `settings.document.design` (design key; the spec default `default` means the
  organization's `is_default` design) → that design's current non-retired version → the default
  design (created lazily, version 1 published at creation, an advisory lock serializes concurrent first
  renders). `form_versions.design_version_id` stays unused.
- **Canonical input:** definition, stored (server-evaluated) data, visibility from re-evaluation at the
  submit time, branding, design-version settings, metadata (org, number, status, submitted_at in the org
  time zone, form/design/renderer versions — never the render time) and the sha256 of each inlined asset.
  `input_hash = sha256(canonical JSON of {sha256 of body/header/footer HTML, input, renderer version})`.
  Branding is not versioned: a branding change alters the input hash of a later re-render, which is the
  point of the hash. Automatic renders with an unchanged input hash reuse the latest rendition
  (idempotent job); an explicit re-render always creates a new one.
- **Engine:** spatie/laravel-pdf's Gotenberg driver (`config/laravel-pdf.php`). It posts only
  `index.html`, `header.html` and `footer.html`, so photos (downscaled to ≤ 1600 px), the logo and the
  bundled OFL fonts (`resources/fonts`, @fontsource 5.3.x, latin + latin-ext, 400/700) are data: URIs.
  Header/footer use Chromium's `pageNumber`/`totalPages` placeholders. A 25 MB request budget fails
  before Gotenberg is called. The driver has no timeout option (Laravel's 30 s HTTP default applies); if
  larger documents need more, replace it with a thin client behind `GotenbergClient`.
- **Tenant content:** header/footer rich text passes an allow-list sanitizer (symfony/html-sanitizer,
  already required by Filament); tokens are substituted afterwards in one pass with escaped values.
- **Queueing:** `SubmissionSubmitted` (after commit) → `GenerateRendition` (ids only, tenant restored
  from the organization id, 3 tries with backoff, budget errors fail at once, 75 s timeout below the
  queue's 90 s `retry_after`, `WithoutOverlapping` per submission). The insert runs under a
  per-submission advisory lock that re-checks the duplicate and the design pin. The listener reports and
  swallows dispatch errors so a committed submit never fails. `documents.render_on_submit` defaults to
  off when `APP_ENV=testing`, so unrelated tests never call Gotenberg; document tests enable it.
- **Downloads:** `GET /api/v1/submissions/{uuid}/document` returns a 5-minute signed URL naming the user;
  the signed route re-checks that user's visibility and writes the "downloaded" audit entry.

## Consequences
Gotenberg integration tests need Gotenberg at `GOTENBERG_URL` and poppler's `pdftotext`/`pdfinfo`
(installed on the host; CI must provide both). Changing templates or CSS requires bumping
`DocumentRenderer::VERSION`, which changes every later input hash.
