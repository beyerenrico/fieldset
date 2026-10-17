# 0068: Horizon behind the operator session; how the one-time sign-in links are built
- Status: accepted
- Date: 2026-10-17
- Amends: ADR 0041 (Horizon gate), refines ADR 0056 (implementation)

## Context
Plan U6 (M9-T05, KTD21, KTD25, KTD26), BUILD_PROMPT_V2 R12, R26–R28, R50. Until now Horizon opened for any active
user whose email was listed in `PLATFORM_ADMIN_EMAILS`, whatever the session: an `/admin` session that an
organization's identity provider created was enough. ADR 0041 kept that list "for now". 2.0 installs make the owner
a platform operator (R26), and sign-in links must never yield more than an ordinary `/admin` session (R28). KTD25
says the process holding the Docker socket opens no database connection, so `fsa` runs the link commands as
socket-less one-offs of the app image and passes their JSON through.

## Decision
### Horizon
- `viewHorizon` = `PlatformAdmins::check($user)` (active user with a `platform_admins` row) **and**
  `PlatformSession::isValid($user)` (the session signed in on `/platform` with password and MFA, not older than
  `platform.session_hours`). `PLATFORM_ADMIN_EMAILS` no longer opens Horizon; the OpenAPI docs still use it.
- Horizon's own `local` bypass stays (development stack only).

### Sign-in links
- One table `login_links` for both kinds (`owner`, `admin`): SHA-256 of the token, user, expiry, `used_at`,
  `used_ip`, `created_by` (`cli`), `created_by_uid` (host UID). Nothing references it, so a restore clears stale
  links with a plain `DELETE FROM login_links` (R64). A newer link deletes every unused older one of the user.
- **Owner link** (`app:install --owner-link`): the token is a password-reset broker token (60 minutes, single use,
  broker throttle, `PasswordAuthGate::assertPasswordReset`). The row exists to tell "used" from "invalid" and to
  list unused links; the broker remains the authority (a superseded broker token makes the link invalid).
- **Admin link** (`identity:login-link`, behind `fsa login-link`): 64 random characters, 15 minutes, five per
  account per 15 minutes, `PasswordAuthGate::assertPasswordLogin` (break-glass Owners pass, like a password login).
- **Landing**: `GET /login-link` renders a page whose nonce script moves the fragment into a form and POSTs it,
  then removes the fragment from the address bar. Only `POST /login-link` consumes a token (row lock, 20 attempts
  per IP per minute). States: valid, used, expired, rate-limited, invalid, plus sso-required (an enforced domain
  refuses through the same gate), DE and EN, each with `fsa login-link <email>` where a new link helps.
- **Hand-off**, always the web guard only, never the `/platform` mark or an impersonation:
  - owner: the reset token waits in the session (10 minutes) and Filament's reset page reads it, reached through a
    temporarily signed URL that carries only the email. After the password is set the owner is signed in and lands
    on the profile page with a notice that offers MFA (an owner who already has MFA goes to the normal login);
  - admin with MFA: a 10-minute mark in the session makes the admin login show Filament's MFA challenge for that
    user (Filament's challenge rate limit, wrong codes audited `auth.mfa_failed` via `login_link`);
  - admin without MFA: signed in at once with a new session id.
- **Audit**: issuing writes `login_link.issued` through `AuditLogger::cli()` (actor type `cli`, no causer,
  `host_uid`), using writes `login_link.used` (causer the user, IP in the entry) and `auth.login` with
  `method=login_link`. `AuditLogger::cli()` is the shared way for the other `fsa` audit events (KTD26).
- **Commands** (app side, socket-less, one JSON document in the `fsa` envelope shape, the link only under
  `secrets`, never a password): `app:install` (`--password-file`, `--owner-link`, `--json`, `--actor-uid`;
  `--password` prints a deprecation warning), `identity:login-link {email} --json --actor-uid=`,
  `identity:login-links [--unused] --json`. `app:install` grants the owner the operator role through
  `GrantPlatformAdmin`; `app:platform-admin` stays for later grants.

## Alternatives considered
- **Own "set password" form on the landing page** instead of Filament's reset page: duplicates Filament's password
  rules, rate limits and events.
- **Owner token in the reset page's query string** (Filament's default reset URL): the token would reach access
  logs, which R28 and ADR 0056 rule out.
- **Signing a link user in without the MFA challenge**: would turn a CLI link into a second factor bypass.

## Consequences
- Operators reach Horizon only after a `/platform` sign-in; a stale `PLATFORM_ADMIN_EMAILS` entry grants nothing.
- An owner link can be invalidated by a later "forgot password" request for the same account (new broker token).
- The landing page needs JavaScript; without it the page says so.
