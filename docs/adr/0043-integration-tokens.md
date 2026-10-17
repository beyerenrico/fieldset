# 0043: Integration tokens (Sanctum personal access tokens with abilities)
- Status: accepted
- Date: 2026-10-22

## Context
§10: "Auth uses Sanctum: SPA cookies for the Field App … and personal access tokens with abilities for
integrations. Rate-limited per user and per token." Until M8 only the Field App session existed. M6 had already
added `personal_access_tokens.organization_id`; deactivating a membership deletes that organization's tokens,
deactivating a user deletes all of them, and `ResolveOrganization` refuses a token for another organization. There
was no way to create a token, and no route checked abilities.

Constraints:
- The Field App session must keep working unchanged. Sanctum gives a session a `TransientToken` whose `can()` is
  always true. The test suite's session stand-in, `Sanctum::actingAs($user)` (`apiHeaders()`), sets an *unsaved*
  token double without abilities.
- Tenancy (§6.2): the guard resolves tokens before any tenant is known, so the token table stays unscoped (listed
  in the isolation suite's `UNSCOPED_TENANT_TABLES`).
- Permissions (§8.6, ADR 0017) stay the single authority for what a user may do.

## Decision
### Token model
- A token is Sanctum's `PersonalAccessToken`, owned by a user and bound to one organization (`organization_id`,
  set by `CreateApiToken`). Sanctum stores only the SHA-256 hash; the plain text (`<id>|<secret>`) is shown once.
- New column `first_used_at` and an index on `(organization_id, tokenable_type, tokenable_id)` (migration
  `2026_10_22_300000`).
- Expiry is optional: 7, 30, 90, 180 or 365 days, or never. 90 days is preselected
  (`config('api_tokens.default_expiry_days')`, default 90). Sanctum refuses expired tokens (401).
- A token works only for its organization. A missing `X-Organization-Id` is filled in with the token's
  organization; a different header answers 403 `organization_forbidden`. A token without an organization (only
  possible through code) answers 403 `token_unbound`. The user must still be an active member and active.

### Abilities
`forms:read`, `submissions:read`, `submissions:write`, `documents:read`, `tasks:read`, `tasks:write`,
`notifications:read` (`App\Domain\Identity\Tokens\TokenAbility`).

| Route | Abilities |
|---|---|
| `GET /forms`, `GET /forms/{form}/versions/{version}` | `forms:read` |
| `GET /sync/manifest` (lists forms and tasks) | `forms:read` + `tasks:read` |
| `GET /submissions`, `GET /submissions/{uuid}`, `GET …/attachments/{attachment}` | `submissions:read` |
| `PUT /submissions/{uuid}`, `POST …/submit`, `POST …/attachments` | `submissions:write` |
| `GET /submissions/{uuid}/document` | `documents:read` |
| `POST /submissions/{uuid}/send` (emails the PDF to any address) | `submissions:write` + `documents:read` |
| `GET /tasks` / `POST /tasks/{id}/complete` | `tasks:read` / `tasks:write` |
| `GET /notifications`, `POST /notifications/read`, `GET /me/notification-preferences` | `notifications:read` |
| `GET /me`, `POST /devices`, `PUT /me/notification-preferences` | none: Field App session only (403 `session_required`) |

- Enforcement: `RequireTokenAbility::using(...)` on every authenticated route in `routes/api.php`; all listed
  abilities are required. A missing one answers 403 `insufficient_ability` with
  `details: { required: [...], missing: [...] }`. The wildcard `*` (Sanctum's default) grants nothing.
- Deny by default: `AuthenticateApiToken` (group middleware) refuses a token on any route that does not declare
  `RequireTokenAbility`. An inventory test lists every authenticated route with its abilities, so a new route
  needs a deliberate entry.
- "Token request" means a **stored** `PersonalAccessToken` (`RequestToken::of()`). The `TransientToken` of a
  session and the unsaved test double keep full session access, so the Field App and the existing API tests
  are unchanged. Ability tests use real bearer tokens.
- Abilities never widen access: controllers and policies check the user's role permissions as before. A token
  can do at most the intersection of its abilities and its user's permissions.
- Notifications: a token sees only its organization's notifications, not those of other organizations or those
  without an organization (`NotificationInbox::activeMemberships`). `GET /me` stays session-only because it lists
  every organization of the user.

### Who manages tokens
- Page "API tokens" (Team group, `/admin/{org}/api-tokens`). Every active panel user (`admin.access`) creates,
  lists and revokes their own tokens in the current organization. Holders of `members.manage` (Owners, Admins)
  also see every token of the organization, with its user, and may revoke it.
- No new capability: tokens are personal and never exceed their user, and the page lives in the panel, which
  already needs `admin.access`. A field-worker-only integration needs a user with `admin.access` (for example a
  custom "Integration" role). A dedicated `api.tokens` capability can be added later: change
  `ApiTokenAccess::canCreate()` and add the enum case and translations (ADR 0017).
- No token can be created while impersonating (ADR 0041): an operator must not leave a credential behind.

### Rate limit and audit
- The `api` limiter keys by `ApiRateLimitKey`: `token:<id>` for a token, else `user:<id>`, else `ip:<ip>`. Every
  token has its own 240/min bucket, separate from its user's Field App session.
- Audit events (`AuditLogger`, subject = the token, organization = the token's): `api_token.created` (name,
  abilities, expires_at, owner_id), `api_token.revoked`, and `api_token.first_used` (actor type `api_token`).
  First use is recorded exactly once by a conditional update of `first_used_at`; later uses only move Sanctum's
  `last_used_at`. Property keys avoid "token" because the Redactor masks such keys; no secret or hash is logged.

## Consequences
- Integrations can pull forms, submissions and documents, and push drafts and submissions, without a session.
- Two lines outside the token code: the `api` limiter in `AppServiceProvider` and the token filter in
  `NotificationInbox`.
- A signed document link (`GET /submissions/{uuid}/document`, 5 minutes) issued before a revocation stays valid
  until it expires; the download re-checks that the user may still see the submission.
- The plain text of a new token is part of the Livewire state while its modal is open (as for webhook secrets);
  no request-recording tool (Telescope, Debugbar) is installed, and none should record Livewire payloads.
- Expired tokens are shown as "Expired" and deleted by the daily `sanctum:prune-expired --hours=24` schedule
  (routes/console.php), one day after they expire.
- Tests that need ability checks must send real bearer tokens and call `forgetGuards()` between requests (the
  test application caches the user resolved by the sanctum guard).
