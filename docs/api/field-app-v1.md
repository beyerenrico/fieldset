# Field App API contract (`/api/v1`, milestone M2)

Normative for the Field App and the backend; Scramble publishes the generated OpenAPI 3.1 document at
`/docs/api`. All responses use the envelope `{ success, data, error: { code, message, details } | null, meta }`.
Validation errors: `error.code = "validation_failed"`, `error.details = [{ pointer, code, message? }]` with
JSON Pointers into the request body (for submissions: into the submission data, e.g. `/items/2/qty`).

## Authentication

- SPA cookie auth (Sanctum stateful, same origin): `GET /sanctum/csrf-cookie`, then
  `POST /auth/login` `{ email, password, remember: true }` (Fortify, JSON). Response `{ two_factor: bool }`.
  If `two_factor` is true: `POST /auth/two-factor-challenge` `{ code }` or `{ recovery_code }` → 204.
  `POST /auth/logout` → 204. Send `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie on every mutating request.
- Every tenant-scoped call sends `X-Organization-Id: <id>` (from `GET /me`).
- Sessions are long and rolling (30 days). On **401/419**, call `POST /api/v1/auth/device-status` (below).

## Endpoints

| Method & path | Auth | Purpose |
|---|---|---|
| `GET /me` | session | `{ user: {id, name, email, locale, timezone, email_verified, mfa_enabled}, organizations: [{id, name, slug, locale, timezone, branding, roles[], permissions[], groups[{id,name}]}] }` |
| `POST /devices` | session | Register this install: body `{ device_uuid, name, platform }` → `{ device_uuid, secret }` (secret returned once; store it in IndexedDB). Re-registering the same uuid by the same user rotates the secret. |
| `POST /auth/device-status` | headers `X-Device-Id`, `X-Device-Secret` (session optional) | `{ status: "ok" \| "reauth" \| "wipe" }`. `wipe`: device revoked, wipe requested, or user deactivated → clear all local data. `reauth`: no valid session → log in again, keep the outbox. `ok`: session valid → refresh CSRF cookie and retry. |
| `GET /forms` | session + org | Forms the user may fill: `[{ id, key, name, category, version: { id, version, checksum } }]`. Only active forms whose current version is not retired and that the user is granted (Owner/Admin: all). |
| `GET /forms/{form}/versions/{version}` | session + org | `{ id, form_id, version, checksum, definition, design, published_at, retired }` (any version of a granted form, for drafts pinned to older versions). |
| `GET /sync/manifest?since=<cursor>` | session + org | `{ cursor, forms: [same as GET /forms], revoked_form_ids: [id], tasks: [] }`. `since` omitted = everything. |
| `PUT /submissions/{uuid}` | session + org | Create or update a **draft**. Body `{ form_version_id, data, revision?, device?, geo? }`. Create: no `revision` (or `0`) → 201 `{ submission }` with `revision: 1`. Update: `revision` must equal the stored revision, else **409** `error.code = "revision_conflict"`, `error.details = { submission }` (server copy). Editable only while `status` is `draft` or `changes_requested` (else 409 `not_editable`). `uuid` is client-generated (UUIDv7). |
| `POST /submissions/{uuid}/attachments` | session + org | multipart: `file`, `attachment_uuid`, `kind` (`photo`\|`file`\|`signature`), `element_key`. Idempotent by `attachment_uuid` (same uuid again → 200 with the existing record). Images are re-encoded server-side (EXIF stripped). → `{ attachment: { uuid, kind, element_key, mime, size, sha256, url } }` |
| `GET /submissions/{uuid}/attachments/{attachment_uuid}` | session + org | file download (private). |
| `POST /submissions/{uuid}/submit` | session + org, header `Idempotency-Key` | Body `{ revision }`. Server re-evaluates the data (authoritative calculations), checks referenced attachments exist on this submission, assigns the number (gap-free per form and period). → `{ submission }` with `status: "submitted"`, `number`. Errors: 422 `validation_failed` (pointer details), 409 `revision_conflict`, 409 `version_retired`, 403 `form_access_revoked`, 422 `attachments_missing` (`details: [attachment_uuid]`). Replaying the same `Idempotency-Key` returns the original response. |
| `GET /submissions?status=&form_id=&page=` | session + org | Submissions visible to the user (own; group if granted). Paginated, `meta: {current_page, last_page, total}`. |
| `GET /submissions/{uuid}` | session + org | `{ submission }` |

`submission` object:
```json
{
  "uuid": "0192f1c4-…", "form_id": 3, "form_version_id": 7, "version": 2,
  "status": "draft|submitted|in_review|changes_requested|approved|completed|void",
  "revision": 4, "number": "SR-2026-00012", "data": { },
  "attachments": [{ "uuid": "…", "kind": "photo", "element_key": "photos", "mime": "image/jpeg", "size": 12345, "url": "…" }],
  "submitted_at": "2026-10-16T09:41:00+02:00", "created_at": "…", "updated_at": "…",
  "can_edit": true
}
```

## Evaluation context for the client renderer

The client runs `evaluate(definition, data, context)` from `@form-schema-app/form-schema-js` with
`context = { today, now, user: { name, email }, org: { name }, formVersion }` (today/now in the
organization time zone). Server results are authoritative; calculated values sent by the client are
ignored.
