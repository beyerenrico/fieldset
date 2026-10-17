# Field App API contract (`/api/v1`, milestone M2; offline additions M6; SSO M7; integration tokens M8)

Normative for the Field App and the backend; Scramble publishes the generated OpenAPI 3.1 document at
`/docs/api`. Paths in the endpoint table are relative to `/api/v1`. All `/api/v1` responses use the envelope
`{ success, data, error: { code, message, details } | null, meta }`.
Validation errors: `error.code = "validation_failed"`, `error.details = [{ pointer, code, message? }]` with
JSON Pointers into the request body (for submissions: into the submission data, e.g. `/items/2/qty`).
Non-multipart request bodies larger than 2 MB (`submissions.max_payload_bytes`) → **413** `payload_too_large`.

## Authentication

- SPA cookie auth (Sanctum stateful, same origin). These paths are at the **site root**, not under
  `/api/v1`, and Fortify answers with plain JSON (no envelope; validation errors use Laravel's
  `{ message, errors }` with HTTP 422, throttling HTTP 429): `GET /sanctum/csrf-cookie`, then
  `POST /auth/login` `{ email, password, remember: true }`. Response `{ two_factor: bool }`.
  If `two_factor` is true: `POST /auth/two-factor-challenge` `{ code }` or `{ recovery_code }` → 204.
  `POST /auth/logout` → 204. Send `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie on every mutating request.
- Every tenant-scoped call sends `X-Organization-Id: <id>` (from `GET /me`).
- **Integration tokens (M8, ADR 0043).** Other systems authenticate with `Authorization: Bearer <id>|<secret>`, a
  Sanctum personal access token created in the dashboard ("API tokens"; shown once). A token belongs to one user
  and one organization; it can do at most the intersection of its abilities and the user's role permissions.
  The rules below apply only to such tokens; the Field App session keeps full access and is unchanged.
  - Abilities per endpoint: `forms:read` (`GET /forms`, `GET /forms/{form}/versions/{version}`);
    `forms:read` + `tasks:read` (`GET /sync/manifest`); `submissions:read` (`GET /submissions`,
    `GET /submissions/{uuid}`, `GET …/attachments/{attachment_uuid}`); `submissions:write`
    (`PUT /submissions/{uuid}`, `POST …/attachments`, `POST …/submit`); `documents:read`
    (`GET …/document`); `submissions:write` + `documents:read` (`POST …/send`); `tasks:read` (`GET /tasks`);
    `tasks:write` (`POST /tasks/{id}/complete`); `notifications:read` (`GET /notifications`,
    `POST /notifications/read`, `GET /me/notification-preferences`; only the token organization's
    notifications). `GET /me`, `POST /devices` and `PUT /me/notification-preferences` are session-only.
  - A missing ability → **403** `insufficient_ability`, `details: { required: [ability], missing: [ability] }`; a
    session-only endpoint → **403** `session_required`. The wildcard `*` grants nothing.
  - `X-Organization-Id` is optional: without it the token's organization applies; another organization → **403**
    `organization_forbidden`. A token without organization → **403** `token_unbound`.
  - Revoked or expired (optional expiry, 90 days preselected) → **401** `unauthenticated`. Deactivating the member
    (or the user) deletes the tokens.
  - Rate limit: 240/min per token (`app.api_rate_limit`), separate from the user's session bucket.
- **Email-first sign-in (M7, ADR 0036).** The client asks for the email, then calls home-realm discovery:
  `POST /auth/sso/discover` `{ email }`. It is a web route at the site root like the Fortify calls: CSRF
  cookie and `X-XSRF-TOKEN`, plain JSON, invalid email → 422 `{ message, errors }`, throttled (10/min per address and IP, 300/min per IP) → 429.
  The answer is `{ mode: "password" }` or `{ mode: "sso", redirect_url, organization, enforced }`
  (`organization`: display name; `enforced`: password login is refused). Discovery works per domain only
  (a verified domain → its organization's active provider) and never reveals whether a user exists.
  - `password`: the password step (`POST /auth/login` as above), with the email prefilled.
  - `sso` with `enforced: true`: the client writes open drafts to the device, sends a pending
    `POST /auth/logout`, then navigates the browser to `redirect_url`
    (`GET /auth/sso/{provider}/redirect?client=app…`, same origin).
  - `sso` with `enforced: false`: the client offers "Continue with <organization>" or "Sign in with a
    password" (the password step works as above). After `sso_failed` or `provider_unavailable` the
    password option is offered too; the server still refuses enforced domains with `sso_required`.
  - Discovery needs the network. Offline, the client says so; an offline start from the cached session
    (M6) is unchanged.
- **Return from the identity provider.** The SSO login ends with a redirect to `/app/login?sso=ok`: the
  session is established (and regenerated), and the client runs its normal post-login steps (`POST /devices`,
  `GET /me`, the foreign-data prompt, a sync). Local 2FA is skipped for SSO logins (the IdP's job). A refused
  login returns to `/app/login?sso_error=<code>`, with `code` one of `sso_failed`, `sso_denied`,
  `account_inactive`, `not_member`, `domain_not_verified`, `email_unverified`, `provider_unavailable`
  (clients show unknown codes as `sso_failed`).
- **Enforced SSO.** When the email's domain is verified by an organization that enforces SSO,
  `POST /auth/login` answers **422** `{ message, errors: { sso_required: [msg] } }` before any password check,
  and `POST /auth/forgot-password` answers 422 with `errors.sso_required` too. The client offers single
  sign-on (discovery again). Only the provider's break-glass Owner keeps password + 2FA.
- Sessions are long and rolling (30 days). On **401/419**, call `POST /api/v1/auth/device-status` (below).
- **Membership deactivated (M6):** deactivating a member in an organization deletes the member's API tokens
  for that organization, ends **all** of the member's sessions (sessions are not bound to an organization)
  and forgets the remember-me token. When the member has no other active membership left, every device of
  the member is asked to wipe (`wipe_requested_at`; reactivating the membership does not lift it). The
  next call therefore normally answers 401 → device-status → `wipe` (no active membership left) or
  `reauth` (still a member elsewhere; after logging in, `GET /me` no longer lists the organization).
- **403 `organization_forbidden`** (the `X-Organization-Id` is not an active membership, e.g. a session
  that outlived a deactivation): refetch `GET /me`. If the organization is no longer listed, purge that
  organization's local rows (drafts, outbox, cached forms) and switch to another organization. If `/me`
  lists **no** organization at all, call `POST /auth/device-status` and follow its answer (`wipe` → clear
  everything; `reauth` → log in again).

## Endpoints

| Method & path | Auth | Purpose |
|---|---|---|
| `GET /me` | session | `{ user: {id, name, email, locale, timezone, email_verified, mfa_enabled}, organizations: [{id, name, slug, locale, timezone, branding, roles[], permissions[], groups[{id,name}]}] }` |
| `POST /devices` | session | Register this install: body `{ device_uuid, name, platform }` → `{ device_uuid, secret }` (secret returned once; store it in IndexedDB). Re-registering the same uuid by the same user rotates the secret. |
| `POST /auth/device-status` | headers `X-Device-Id`, `X-Device-Secret` (session optional) | `{ status: "ok" \| "reauth" \| "wipe" }`; unknown device or wrong secret → 401 `device_unknown` (treat as `reauth`, then re-register). `wipe`: device revoked, wipe requested (also when the user's last active membership was deactivated), or user deactivated → clear all local data. `reauth`: no valid session → log in again, keep the outbox. `ok`: session valid → refresh CSRF cookie and retry. |
| `GET /forms` | session + org | Forms the user may fill: `[{ id, key, name, category, version: { id, version, checksum } }]`. Only active forms whose current version is not retired and that the user is granted (Owner/Admin: all). |
| `GET /forms/{form}/versions/{version}` | session + org | `{ id, form_id, version, checksum, definition, design, published_at, retired }` (any version of a granted form, for drafts pinned to older versions; also the version of any submission the user may see, so reviewers and viewers can render what they review). |
| `GET /sync/manifest?since=<cursor>` | session + org | `{ cursor, forms: [same as GET /forms], fillable_form_ids: [id], revoked_form_ids: [id], retired_version_ids: [id], tasks: [same as GET /tasks items, open tasks only] }`. `since` omitted = everything. `since` is inclusive (the cursor has second precision), so a form can appear in two consecutive manifests: upsert by id. `forms` includes forms whose grants changed and forms reached through a group membership added since the cursor. `fillable_form_ids` is complete: purge every cached form not in it (`revoked_form_ids` is best-effort); fetch any id in it that is not cached via `GET /forms` (covers role changes, which touch no form). `retired_version_ids` (M6): versions of fillable forms retired at or after `since` (every retired version of a fillable form without `since`); retiring changes no form row, so mark these cached versions `retired` (drafts pinned to them can no longer be submitted: offer "copy to the current version"). A form whose current version was retired is no longer fillable and leaves `fillable_form_ids`. |
| `PUT /submissions/{uuid}` | session + org | Create or update a **draft**. Body `{ form_version_id, data, revision?, device?, geo? }`. Create: no `revision` (or `0`) → 201 `{ submission }` with `revision: 1`; a retired version → **409** `version_retired`. Update: `revision` must equal the stored revision, else **409** `error.code = "revision_conflict"`, `error.details = { submission }` (server copy). `form_version_id` must equal the pinned version, else **409** `version_mismatch`. Editable only while `status` is `draft` or `changes_requested` (else 409 `not_editable`). Another user's draft → 403 `forbidden` if the caller can see it, else 404 `not_found`. **Retries are safe:** repeating the last successful save (same data, the revision it was sent with, or no revision for the create) → 200 with the current draft, also when two creates of the same uuid race. `uuid` is client-generated (UUIDv7). |
| `POST /submissions/{uuid}/attachments` | session + org | multipart: `file`, `attachment_uuid`, `kind` (`photo`\|`file`\|`signature`), `element_key`. Idempotent by `attachment_uuid` (same uuid again, also from a concurrent upload → 200 with the existing record); a uuid already used by another submission → **409** `attachment_conflict`. Images are re-encoded server-side (the EXIF orientation is applied to the pixels, then EXIF is stripped; images above 25 megapixels are scaled down to 25 MP so they can be printed); images over 12000 px per side or 40 megapixels → 422 `image_too_large`. → `{ attachment: { uuid, kind, element_key, mime, size, sha256, url } }` |
| `GET /submissions/{uuid}/attachments/{attachment_uuid}` | session + org | file download (private). `Content-Security-Policy: default-src 'none'; sandbox`; images are served `inline`, other files as `Content-Disposition: attachment`. |
| `POST /submissions/{uuid}/submit` | session + org, header `Idempotency-Key` | Body `{ revision, client_submitted_at? }`. `client_submitted_at` (M6, optional): when the user submitted on the device, ISO 8601 date-time with an offset (`2026-10-16T07:41:00.123Z`, `…+02:00`); not before `2000-01-01T00:00:00Z` (else code `after_or_equal`) and at most 5 minutes ahead of the server clock (else 422 `validation_failed`, pointer `/client_submitted_at`, code `before_or_equal`; a malformed value → code `regex`/`date`/`string`). Stored by every submit, also a resubmit (absent → `null`), unchanged by a repeated submit; informational only: number, period, `submitted_at` and `$today` stay on server time. Server re-evaluates the data (authoritative calculations), checks referenced attachments exist on this submission, assigns the number (gap-free per form and period). → `{ submission }` with `status: "submitted"`, `number`. Errors: 422 `validation_failed` (pointer details), 409 `revision_conflict`, 409 `version_retired`, 403 `form_access_revoked`, 422 `attachments_missing` (`details: [attachment_uuid]`). Replaying the same `Idempotency-Key` returns the original **successful** response byte for byte (header `Idempotent-Replayed: true`, kept 24 h); error responses are not stored, so after fixing the cause (upload the missing attachment, regain access) the same key runs again. Concurrent requests with one key are serialized: the second waits and replays. Repeating the submit of the revision it consumed (lost response, any key) → 200 with the current `{ submission }` (number unchanged). Workflows start asynchronously after the submit: the response says `submitted`, but the status may change right afterwards (`in_review`, `completed`); refetch the submission (`GET /submissions/{uuid}`) before showing a status-dependent screen. A missing/invalid key → 400 `idempotency_key_required`; the same key with a different body → 409 `idempotency_key_reused`. |
| `GET /submissions?status=&form_id=&page=` | session + org | Submissions visible to the user (own; group if granted). Paginated, `meta: {current_page, last_page, total}`. |
| `GET /submissions/{uuid}` | session + org | `{ submission }` |
| `GET /tasks?status=open\|completed` | session + org | Review tasks (M4) assigned to the user or one of their groups, newest first, paginated: `[{ id, type: "review", status: "open"\|"completed"\|"cancelled", outcome: "approved"\|"rejected"\|null, comment, due_at, overdue, created_at, assignee: { type: "user"\|"group", id, name }, submission: { uuid, number, status, form: { id, name }, submitted_by: { id, name }, submitted_at } }]`. Default `status=open`. |
| `POST /tasks/{id}/complete` | session + org, header `Idempotency-Key` | Body `{ outcome: "approve"\|"reject", comment? }` (`comment` required for `reject`, max 2000). Approve continues the workflow; reject sets the submission to `changes_requested` with the comment and notifies the submitter. → `{ task, submission }`. Errors: 409 `task_closed` (already completed by anyone, or cancelled because the submission left review another way), 409 `review_blocked` (the workflow behind the review failed and must be retried by the office first; the task stays open — show `error.message` and let the user try again later), 404 for tasks the user cannot act on (not assignee/group member, cannot see the submission, or other org), 422 `validation_failed` (`/comment`, `/outcome`). |
| `GET /notifications?page=` | session (+ org optional) | In-app notifications of the user (newest first, paginated): `[{ id, event, title, body, url, organization_id, read_at, created_at }]` across all organizations the user is an active member of, plus notifications without an organization (the `X-Organization-Id` header is ignored; a deactivated membership hides that organization's notifications), `meta: { current_page, last_page, total, unread }`. `title`/`body` are rendered in the user's language; `url` opens the target (Field App path like `/app/submissions/{uuid}` or an admin URL). |
| `POST /notifications/read` | session | Body `{ ids?: [string] }`; omitted `ids` marks all as read. → `{ unread }`. |
| `GET /me/notification-preferences` | session | `{ events: [{ event, channels: { database: bool, mail: bool } }] }` for every event the user can receive. |
| `PUT /me/notification-preferences` | session | Body `{ events: [{ event, channels: { database?: bool, mail?: bool } }] }` (partial updates allowed) → same shape as GET. Unknown events → 422. |
| `GET /submissions/{uuid}/document` | session + org | Latest rendition (PDF) of a submission the user may see: `{ document: { url, expires_at, rendition: { uuid, form_version_id, design_version_id, renderer_version, page_count, size, file_sha256, input_hash, created_at } } }`. `url` is a signed link valid for 5 minutes (`documents.link_ttl_minutes`), usable without the session (`GET /api/v1/documents/{rendition_uuid}?user=…&expires=…&signature=…` → `application/pdf` download); the download re-checks that the user it was issued to can still see the submission (else 404), a tampered or expired link → 403. No rendition yet (rendering is queued after submit) → **404** `document_not_ready`; rendering failed permanently (e.g. over the size budget; the office is notified and can re-render) → **409** `document_failed` with a user-facing `message`; a submission the user cannot see → 404 `not_found`. |
| `POST /submissions/{uuid}/send` | session + org, header `Idempotency-Key` | Email the document to a recipient (§9.2 "send to customer", M3). Body `{ email?, name? }`; an omitted field defaults to the submitted value of the element with role `customer.email` / `customer.name`; `name: null` or `""` means "no name" (no fallback). Allowed when the pinned version has `settings.sendToCustomer: true`, the submission is no longer a draft (and not `void`), and the user created it or holds `submissions.review`/`organization.manage` and can see it. → **202** `{ send: { id, status, recipient: { email, name } } }` with `status: "waiting_for_document"` (no rendition yet; the mail goes out automatically once the PDF exists) or `"queued"` (mail queued; its delivery appears in the admin mail log). Errors: 409 `send_not_allowed`, 409 `not_submitted`, 422 `validation_failed` (`/email` missing or invalid), **429** `too_many_requests` (caps: 10 sends per submission in 24 h, 200 per organization per hour; configurable), 404 for submissions the user cannot see. Same idempotency rules as submit. A request that waits for the PDF fails (visible to the office) if rendering fails permanently or the submission is voided meanwhile. **Durable idempotency (M6):** the key is also stored on the send itself (unique per submission), so a key replayed after the 24 h replay store was pruned → **202** with the existing `send` (current status, original recipient, whatever body the replay carries), no second mail and no cap consumed. |

`submission` object:
```json
{
  "uuid": "0192f1c4-…", "form_id": 3, "form_version_id": 7, "version": 2,
  "status": "draft|submitted|in_review|changes_requested|approved|completed|void",
  "revision": 4, "number": "SR-2026-00012", "data": { },
  "attachments": [{ "uuid": "…", "kind": "photo", "element_key": "photos", "mime": "image/jpeg", "size": 12345, "url": "…" }],
  "submitted_at": "2026-10-16T09:41:00+02:00", "client_submitted_at": "2026-10-16T07:39:12+00:00", "created_at": "…", "updated_at": "…",
  "can_edit": true,
  "can_send": false,
  "review": { "outcome": "rejected", "comment": "Bitte Seriennummer ergänzen", "by": { "id": 4, "name": "Rita Reviewer" }, "at": "…" },
  "open_task_id": null
}
```

`can_edit` is true when the **current user** may change the data now (creator, status `draft` or `changes_requested`). `can_send` is true when `POST /submissions/{uuid}/send` would be accepted for this user now. `review` is the latest review decision (null before any); a `changes_requested` submission is editable by its creator and is resubmitted with `POST /submissions/{uuid}/submit` (number kept). `open_task_id` is the id of an open review task for this submission that the current user may complete, else null. `client_submitted_at` is the device time of the latest submit (null when the client sent none or before the first submit).

## Evaluation context for the client renderer

The client runs `evaluate(definition, data, context)` from `@form-schema-app/form-schema-js` with
`context = { today, now, user: { name, email }, org: { name }, formVersion }` (today/now in the
organization time zone). Server results are authoritative; calculated values sent by the client are
ignored.
