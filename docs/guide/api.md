# API and integrations

Fieldset offers two ways to connect other systems: the REST API at `/api/v1`, which the Field App
uses, and outbound webhooks sent by workflows.

Contents:
- [REST API (/api/v1)](#rest-api-apiv1)
- [OpenAPI documentation](#openapi-documentation)
- [Authentication](#authentication)
- [Integration tokens](#integration-tokens)
- [Webhooks](#webhooks)
- [Form definitions (Fieldset Spec)](#form-definitions-fieldset-spec)

## REST API (/api/v1)

The normative contract is [docs/api/field-app-v1.md](../api/field-app-v1.md). In short:
- JSON over HTTPS. Every response uses the envelope
  `{ "success": bool, "data": …, "error": { "code", "message", "details" } | null, "meta": … }`.
  Validation errors carry JSON Pointers into the request body.
- Every organization-scoped call sends the header `X-Organization-Id` (the ids come from `GET /me`).
- Writes are idempotent: submissions and attachments use client-generated UUIDs, and submit, customer
  send and review decisions take an `Idempotency-Key` header.
- Non-multipart request bodies are limited to 2 MB (413 `payload_too_large`).
- Rate limits: 240 requests per minute (`API_RATE_LIMIT`) per user session and, separately, per
  integration token, and lower limits on some endpoints (for example 30 customer sends and 120 attachment
  uploads per minute). Over the limit the API answers 429 `too_many_requests`.

Main endpoints (paths relative to `/api/v1`):

| Method and path | Purpose |
|---|---|
| `GET /me` | User, organizations, roles, groups, branding |
| `POST /devices`, `POST /auth/device-status` | Field App device registration and the `ok` / `reauth` / `wipe` check |
| `GET /forms`, `GET /forms/{form}/versions/{version}` | Forms the user may fill, and a version's definition |
| `GET /sync/manifest?since=` | What changed since the last sync |
| `GET /submissions`, `GET /submissions/{uuid}` | List and detail |
| `PUT /submissions/{uuid}` | Create or update a draft |
| `POST /submissions/{uuid}/attachments` | Upload a photo, signature or file |
| `POST /submissions/{uuid}/submit` | Submit; returns the number and status |
| `GET /submissions/{uuid}/document` | Short-lived signed link to the latest PDF |
| `POST /submissions/{uuid}/send` | Email the PDF to a customer |
| `GET /tasks`, `POST /tasks/{id}/complete` | Review tasks: approve or request changes |
| `GET /notifications`, `POST /notifications/read` | Notification centre |
| `GET`/`PUT /me/notification-preferences` | Notification preferences |

The API applies the same permissions as the dashboard: a user only sees forms, submissions and
attachments of organizations they belong to and forms they were granted.

`GET /submissions/{uuid}/document` answers 404 `document_not_ready` while the PDF is being created and
409 `document_failed` when it could not be created. Once retention has anonymized the submission and
deleted its PDF files, it answers 410 `document_anonymized`, also on the signed download link. That
state is permanent: do not poll.

## OpenAPI documentation

The application publishes an OpenAPI 3.1 description of `/api/v1`, generated from the code:
- `https://<your server>/docs/api`: interactive documentation;
- `https://<your server>/docs/api.json`: the OpenAPI document.

Both require a signed-in user (sign in at `/admin/login` first) who is an organization Owner or Admin
(the "manage members" permission) or listed in `PLATFORM_ADMIN_EMAILS`.

## Authentication

The API accepts two kinds of credentials: the Field App's cookie session (below) and
[integration tokens](#integration-tokens) for other systems.

The Field App uses cookie-based sessions on the same origin (Laravel Sanctum's SPA mode): it fetches
`/sanctum/csrf-cookie`, signs in with `POST /auth/login` (or single sign-on) and sends the
`X-XSRF-TOKEN` header on every write. For this to work, the instance's host name must be a stateful domain: an
instance installed with `fieldset` adds its own host names by itself; a 1.0 checkout install lists them in
`SANCTUM_STATEFUL_DOMAINS` (see the [1.0 install guide](archive/1.0-install.md#2-create-the-env-file)). Sessions last
30 days from the last use. The details, including two-factor authentication and the device check, are in
the [API contract](../api/field-app-v1.md#authentication).

## Integration tokens

Other systems (an ERP, a BI tool, a script) call the API with a personal access token. A token belongs to
one user and one organization, and it can never do more than that user's role allows in that organization.

### Create a token

1. In the dashboard, open "Team" → "API-Tokens" ("API tokens"). Every user who can open the dashboard
   manages their own tokens there. Owners and Admins also see the tokens of all members of the organization
   and can revoke them.
2. Choose "Neues Token" ("New token"), enter a name (what the token is for), tick the permissions the
   integration needs, and choose when the token expires (90 days by default; 7 to 365 days, or never).
3. Copy the token from the dialog. It is shown **only once**; the server keeps only a hash. The dialog also
   shows the organization ID.

The list shows each token's name, permissions, last use and expiry. "Widerrufen" ("Revoke") ends access at
once. Deactivating a member deletes that member's tokens for the organization; deactivating a user account
deletes all of the user's tokens.

A field-worker-only role cannot open the dashboard. For an integration that fills forms, create a user with a
role that has dashboard access ("admin.access") and the form permissions the integration needs, for example a
custom role "Integration".

### Permissions (abilities)

| Permission | Ability | Endpoints |
|---|---|---|
| Formulare lesen (Read forms) | `forms:read` | `GET /forms`, `GET /forms/{form}/versions/{version}` |
| Einreichungen lesen (Read submissions) | `submissions:read` | `GET /submissions`, `GET /submissions/{uuid}`, `GET /submissions/{uuid}/attachments/{attachment}` |
| Einreichungen schreiben (Write submissions) | `submissions:write` | `PUT /submissions/{uuid}`, `POST /submissions/{uuid}/attachments`, `POST /submissions/{uuid}/submit` |
| Dokumente lesen (Read documents) | `documents:read` | `GET /submissions/{uuid}/document` |
| Prüfaufgaben lesen (Read review tasks) | `tasks:read` | `GET /tasks` |
| Prüfaufgaben erledigen (Complete review tasks) | `tasks:write` | `POST /tasks/{id}/complete` |
| Benachrichtigungen lesen (Read notifications) | `notifications:read` | `GET /notifications`, `POST /notifications/read`, `GET /me/notification-preferences` |

- `GET /sync/manifest` needs `forms:read` and `tasks:read`; `POST /submissions/{uuid}/send` needs
  `submissions:write` and `documents:read` (it emails the PDF).
- `GET /me`, `POST /devices` and `PUT /me/notification-preferences` are for the Field App only: tokens get 403
  `session_required`.
- The user's role is checked as well. For example, a token with `submissions:write` of a user who may not fill a
  form still gets 403 for that form, and `GET /forms` lists only the forms the user may fill.
- A token sees only the notifications of its own organization.

### Call the API

Send the token as a bearer token. The organization header is optional for tokens: without it the token's
organization is used, and any other organization is refused.

```sh
curl "https://forms.example.com/api/v1/submissions?status=submitted" \
  -H "Authorization: Bearer 12|t8Kq…" \
  -H "Accept: application/json"
```

Errors (in the usual envelope):

| Status and code | Meaning |
|---|---|
| 401 `unauthenticated` | Token unknown, revoked or expired |
| 403 `insufficient_ability` | The token lacks a permission; `error.details.missing` lists it |
| 403 `session_required` | Endpoint reserved for the Field App |
| 403 `organization_forbidden` | `X-Organization-Id` names another organization, or the user is no longer a member |
| 403 `token_unbound` | Token without organization (not created in the dashboard); create a new one |
| 429 `too_many_requests` | Over 240 requests per minute for this token (each token has its own limit) |

Creating and revoking a token and its first use appear in the audit log; the token value never does.

## Webhooks

A workflow step "Webhook aufrufen" ("Call webhook") sends a signed JSON `POST` to a webhook endpoint
that an administrator configured under "Automatisierung" → "Webhook-Endpunkte" (see the
[admin guide](admin-guide.md#workflows-and-webhooks)).

### Request

Headers:

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-Timestamp` | Unix time in seconds when the request was signed |
| `X-Signature` | `sha256=` followed by the hex HMAC-SHA256 of `<X-Timestamp>.<raw body>`, keyed with the endpoint's signing secret |
| `X-Webhook-Id` | Delivery id, the same for every retry of one step, for example `run-12-step-2` |
| `User-Agent` | `<APP_NAME> Webhooks` |

Body:

```json
{
  "event": "submission.submitted",
  "delivery_id": "run-12-step-2",
  "occurred_at": "2026-10-18T09:15:02+00:00",
  "organization": { "id": 1, "name": "Example GmbH" },
  "workflow": { "id": 3, "name": "Service report to ERP", "run_id": 12 },
  "form": { "id": 5, "key": "service_report", "name": "Servicebericht" },
  "submission": {
    "uuid": "0192f6d4-…",
    "number": "SR-2026-00042",
    "status": "submitted",
    "form_id": 5,
    "version": 2,
    "submitted_at": "2026-10-18T09:14:58+00:00",
    "submitted_by": 7
  },
  "data": { "customer_name": "Muster AG", "total": "1234.50", "items": [ … ] }
}
```

- `event` is the workflow's trigger: `submission.submitted`, `submission.status_changed`,
  `submission.approved`, `submission.rejected` (changes requested), `form.published`, `schedule` or
  `manual`.
- `data` is the submitted data, keyed by the form's field keys. Money and other decimal values are
  strings, never floating-point numbers.
- Runs without a submission (for example `form.published` or a schedule) send `submission` and `data`
  as `null`.

### Delivery and retries

- The endpoint must answer with a 2xx status within 10 seconds (5 seconds to connect).
- Timeouts, connection errors and the statuses 408, 425, 429 and 5xx are retried with exponential
  backoff: 5 attempts in total, 10 s, 30 s, 90 s and 270 s apart by default. Redirects and other 4xx
  answers fail at once. Failed runs are shown in the dashboard and can be retried by an administrator.
- Delivery is at least once: a retry can deliver the same event again. Use `X-Webhook-Id` to ignore
  duplicates.
- Endpoints must resolve to public IP addresses and use port 80, 443, 8080 or 8443; private, loopback and
  link-local addresses and redirects are refused.

### Verify the signature

Check the signature on the raw request body, before parsing it, and reject requests whose timestamp is
more than 5 minutes away from your clock.

PHP:

```php
<?php
$secret = getenv('FIELDSET_WEBHOOK_SECRET');
$body = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

$expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || ! hash_equals($expected, $signature)) {
    http_response_code(401);
    exit;
}

$event = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
// Deduplicate by $_SERVER['HTTP_X_WEBHOOK_ID'], then process $event.
http_response_code(204);
```

Node.js:

```js
import crypto from 'node:crypto';

export function verifyWebhook(rawBody, headers, secret) {
    const timestamp = headers['x-timestamp'] ?? '';
    const signature = headers['x-signature'] ?? '';
    if (!/^\d+$/.test(timestamp) || Math.abs(Date.now() / 1000 - Number(timestamp)) > 300) {
        return false;
    }
    const expected =
        'sha256=' + crypto.createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex');
    return (
        signature.length === expected.length &&
        crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected))
    );
}
```

`rawBody` must be the exact bytes received (for example `express.raw({ type: 'application/json' })`),
not a re-serialized object.

## Form definitions (Fieldset Spec)

Forms are stored as JSON documents in the open Fieldset Spec format: the specification is in
[spec/form-definition/1.0/](../../spec/form-definition/1.0/README.md), with PHP and JavaScript SDKs in
`packages/` (MIT). In the dashboard, "JSON exportieren" and "JSON importieren" move form definitions
between instances or into version control.
