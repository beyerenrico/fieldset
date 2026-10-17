# API and integrations

form-schema-app offers two ways to connect other systems: the REST API at `/api/v1`, which the Field App
uses, and outbound webhooks sent by workflows.

Contents:
- [REST API (/api/v1)](#rest-api-apiv1)
- [OpenAPI documentation](#openapi-documentation)
- [Authentication](#authentication)
- [Integration tokens](#integration-tokens)
- [Webhooks](#webhooks)
- [Form definitions (Form Schema Spec)](#form-definitions-form-schema-spec)

## REST API (/api/v1)

The normative contract is [docs/api/field-app-v1.md](../api/field-app-v1.md). In short:
- JSON over HTTPS. Every response uses the envelope
  `{ "success": bool, "data": …, "error": { "code", "message", "details" } | null, "meta": … }`.
  Validation errors carry JSON Pointers into the request body.
- Every organization-scoped call sends the header `X-Organization-Id` (the ids come from `GET /me`).
- Writes are idempotent: submissions and attachments use client-generated UUIDs, and submit, customer
  send and review decisions take an `Idempotency-Key` header.
- Non-multipart request bodies are limited to 2 MB (413 `payload_too_large`).
- Rate limits: 240 requests per minute per user, and lower limits on some endpoints (for example 30
  customer sends and 120 attachment uploads per minute). Over the limit the API answers 429
  `too_many_requests`.

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

## OpenAPI documentation

The application publishes an OpenAPI 3.1 description of `/api/v1`, generated from the code:
- `https://<your server>/docs/api`: interactive documentation;
- `https://<your server>/docs/api.json`: the OpenAPI document.

Both require a signed-in user (sign in at `/admin/login` first) who is an organization Owner or Admin
(the "manage members" permission) or listed in `PLATFORM_ADMIN_EMAILS`.

## Authentication

The Field App uses cookie-based sessions on the same origin (Laravel Sanctum's SPA mode): it fetches
`/sanctum/csrf-cookie`, signs in with `POST /auth/login` (or single sign-on) and sends the
`X-XSRF-TOKEN` header on every write. For this to work, the instance's host name must be in
`SANCTUM_STATEFUL_DOMAINS` (see the [install guide](install.md#2-create-the-env-file)). Sessions last
30 days from the last use. The details, including two-factor authentication and the device check, are in
the [API contract](../api/field-app-v1.md#authentication).

## Integration tokens

Planned, not available in 1.0. The database already binds API tokens (Laravel Sanctum personal access
tokens) to one organization, and the API refuses such a token for any other organization, but there is
no screen or command to create tokens, and endpoints do not check token abilities. Use
[webhooks](#webhooks) to push data to other systems, and the exports in the dashboard to pull data.

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
$secret = getenv('FORM_SCHEMA_WEBHOOK_SECRET');
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

## Form definitions (Form Schema Spec)

Forms are stored as JSON documents in the open Form Schema format: the specification is in
[spec/form-definition/1.0/](../../spec/form-definition/1.0/README.md), with PHP and JavaScript SDKs in
`packages/` (MIT). In the dashboard, "JSON exportieren" and "JSON importieren" move form definitions
between instances or into version control.
