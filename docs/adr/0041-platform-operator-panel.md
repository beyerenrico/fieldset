# 0041: Platform operator panel (/platform) with audited impersonation
- Status: accepted
- Date: 2026-10-22

## Context
§8.12 lists an optional `/platform` operator panel: organizations, health, audited impersonation. Until M8 the only
instance-level role was the `PLATFORM_ADMIN_EMAILS` list, which gates Horizon and the OpenAPI docs.

Constraints:
- The web guard and session are shared by `/admin`, the Field App (Fortify) and Filament. `/admin` signs users in
  through an organization's SSO (ADR 0036), and that identity provider belongs to the tenant, not to the instance.
- Tenancy (§6.2): platform code opts out explicitly. `Organization` and `Membership` are not tenant-scoped models.
- Audit (§8.11): every security-relevant action produces exactly one entry. G1's coverage test expects the event
  names `platform.impersonation_started` and `platform.impersonation_ended`.
- CSP (ADR 0040): the panel CSP is nonce-based (`script-src 'self' 'nonce-…' 'unsafe-eval'`, `img-src 'self' data: blob:`).
  Views have no inline scripts. No Tailwind is compiled for our views (LEARNINGS #42).

## Decision
### Who is an operator
- A dedicated table, `platform_admins` (`user_id` unique FK cascade, `granted_by` nullable FK, timestamps). It is not a
  `users` column:
  - grants are explicit rows with a grantor, never mass-assignable through `User`;
  - there is no clash with GDPR anonymization of users (G1);
  - rows are written only by `GrantPlatformAdmin` and `RevokePlatformAdmin` (audited `platform.admin_granted` /
    `platform.admin_revoked`). `PlatformAdminPolicy` denies all UI and API access.
- None is seeded. `php artisan app:platform-admin {email}` grants and `--revoke` revokes. The command warns when the
  user has no MFA yet.
- `PlatformAdmins::check()` = an active user with a row. It is not memoized, so a revocation takes effect on the next request.
- `User::canAccessPanel('platform')` delegates to it. The admin panel logic is unchanged.
- Horizon and the API docs stay on `PLATFORM_ADMIN_EMAILS` for now. They could switch to the same check later (one
  line in `HorizonServiceProvider` / `AppServiceProvider`).

### A separate panel with its own sign-in
- Filament panel `platform` at `/platform`: no tenancy, amber colour, local initials avatars (`InitialsAvatarProvider`,
  no ui-avatars.com).
- **Own login** (`App\Filament\Platform\Pages\Auth\Login`, Filament's password login + MFA challenge, 5 attempts/min
  per IP). A successful sign-in writes a session mark (`PlatformSession`: user id + time).
- `AuthenticatePlatformOperator` (persistent for Livewire) requires:
  - the Filament checks (guest → `/platform/login`, non-operator → 403);
  - the mark for the current user, not older than `platform.session_hours` (default 12, ASVS 3.3.2).
  - Why: a session that signed in through an organization's SSO on `/admin`, or through the Field App, never reaches
    `/platform`, even for an operator. Otherwise a tenant's identity provider could assert an operator's identity.
- **MFA is required** (`isRequired: true`, the shared TOTP of ADR 0016). An operator without MFA is sent to Filament's
  set-up page after the first sign-in.
- No profile, registration or password-reset pages on this panel. Password reset uses the existing flows. MFA
  set-up uses the required page.

### Pages
- **Organizations:** a read-only list with name, slug, active members, created date and status (soft-deleted =
  archived). Access is the operator check, not `OrganizationPolicy`, which answers for members.
- **Members** of one organization: a table of memberships with the operator badge and the Impersonate action.
- **Health:** each check returns ok / warn / fail with a summary and details. `PlatformHealth` turns exceptions into
  a failed result naming only the exception class; details go to the log.
  - **Queues:** Horizon repositories: master supervisor status (inactive fails, paused warns), pending and recently
    failed jobs (failures warn), queue lengths from `WorkloadRepository`, and the `failed_jobs` count.
  - **Gotenberg:** `GET {laravel-pdf.gotenberg.url}/health` with a 2 s timeout and optional basic auth. Only
    scheme://host:port is shown. This is operator configuration (a private host), not tenant input, so the SSRF
    guard does not apply.
  - **Mail:** the default mailer. SMTP gets a 2 s TCP connect (no login, nothing sent). `log`/`array` warn. API
    transports are reported as configured.
  - **Scheduler:** a heartbeat in the cache, written by listeners on `ScheduledTaskStarting`/`Skipped` (no extra
    scheduled command). ≤ 10 min ok, ≤ 60 min warn, older fails, never recorded warns (`documents:sweep` runs every 5 min).
  - **Disk:** free space of the storage volume. < 10 % warns, < 5 % fails.
  - OS calls (`SystemProbes`) and Horizon repositories are injectable for tests.

### Impersonation
- **Start** (`StartImpersonation`) checks every rule on the server, whatever the UI showed:
  - the operator is an active platform admin and not already impersonating (no nesting);
  - the reason is 10–500 characters (required);
  - the organization is not archived;
  - the target is **never a platform admin** (granted, even if deactivated), and is an active member with
    `admin.access` in that organization (`canAccessTenant`).
  - Field-worker-only members are excluded: impersonation is for the admin panel. The Field App would put an
    offline store, a device and sync on the operator's machine.
- **Audit first, then switch:**
  - `platform.impersonation_started` is recorded inside `TenantContext::run($org)`, so the entry carries the
    organization id and appears in that organization's audit log.
  - Properties: `operator_id`, `operator_email`, `target_id`, `target_email`, `organization_id`, `reason`,
    `started_at`, `expires_at`. Causer = operator, subject = target.
- **Quiet session switch** (`SessionLogin`): no Login/Logout events, so there is no `auth.login` for the target.
  - It writes the guard's session key and `password_hash_web` (AuthenticateSession), migrates the session id,
    rotates the CSRF token, and drops per-user state (device binding, `auth.password_confirmed_at`, `url.intended`).
  - The payload lives in server-side session data (`platform.impersonation`) and is parsed strictly; an incomplete
    payload is ignored.
- **Banner:** Filament render hook `BODY_START` on every panel page while the signed-in user is the target. It shows
  the target, the operator, the end time and the minutes left, plus an "Exit impersonation" button (a plain POST form
  with CSRF, no script). White on #92400e (7:1).
- **End** (`EndImpersonation`) audits `platform.impersonation_ended` with the same properties plus `ended_by` and
  `duration_seconds`:
  - `operator`: `POST /platform/impersonation/exit`. It is a panel route outside the operator auth middleware and
    throttled 30/min. It gives 403 without an impersonation, or when the signed-in user is not the target. It
    restores the operator, who keeps the earlier platform mark, and redirects to `/platform`.
  - `expired`: time limit `platform.impersonation.minutes` (default 30, 1–240). Enforced on the next request:
    - by `EnforceImpersonationWindow` in the web and API groups;
    - for Filament panel pages (which do not use the web group), by a `Filament::serving()` hook.
    - It restores the operator and redirects to `/platform`; Livewire follows redirected responses.
  - `logout`: a real Login/Logout event in the impersonated session (`EndImpersonationOnAuthChange`), or a session
    whose user is no longer the target. Only recorded; the operator is not restored.
  - An operator deleted or deactivated during the impersonation is not restored: the session is signed out.
- **The API refuses impersonated sessions** (403 `impersonation_api_blocked`; 401 `impersonation_expired` after the limit).

## Consequences
- Operators sign in twice when they also work in an organization (once on `/admin`, once on `/platform`). This is
  intended: a tenant's SSO never grants instance access.
- An organization's own audit log shows when, by whom and why an operator acted as one of its members. Platform-level
  grants (`platform.admin_*`) have no organization and are visible only in the database or CLI until a platform audit
  view exists.
- The Field App cannot be impersonated (an API block, not an oversight). Supporting a field worker means looking at
  their submissions in `/admin` as an admin member.
- Config keys with code defaults: `platform.impersonation.minutes` (30), `platform.session_hours` (12). A
  `config/platform.php` makes them explicit.
- Needs browser verification (E2E): the banner on `/admin` pages, the exit button, the Livewire redirect after
  starting, and the MFA set-up redirect on first operator sign-in.

## OWASP ASVS L2 self-review (platform panel)
| Area | Requirement (ASVS 4.0.3) | Status |
| --- | --- | --- |
| V1 Architecture | 1.4.1 access control enforced at a trusted service layer | Done: `StartImpersonation`/`EndImpersonation` re-check every rule; the UI only hides |
| V1 Architecture | 1.4.4 single, vetted access-control mechanism | Done: `PlatformAdmins::check` behind `canAccessPanel`, the persistent auth middleware and the actions |
| V2 Authentication | 2.2.1 anti-automation | Done: Filament login throttle 5/min/IP; MFA challenge throttled by Filament; exit route 30/min |
| V2 Authentication | 2.2.3/2.8 MFA for privileged access | Done: MFA required on `/platform`; a session that signed in elsewhere (tenant SSO) needs a platform sign-in |
| V2 Authentication | 2.5.x no default accounts | Done: no operator is seeded; grants only by CLI |
| V3 Session | 3.2.1 new session token on authentication | Done: Filament login regenerates; impersonation start and end migrate the session id and rotate the CSRF token |
| V3 Session | 3.3.2 periodic re-authentication | Done: platform mark expires after 12 h; impersonation after 30 min |
| V3 Session | 3.3.1 logout invalidates | Done: logout during impersonation ends it (`ended_by=logout`); Filament logout invalidates the session |
| V4 Access control | 4.1.1/4.1.3 least privilege, deny by default | Done: deny-all `PlatformAdminPolicy`; read-only organization list; no create/edit/delete |
| V4 Access control | 4.2.1 IDOR | Done: members page and impersonation take the organization from the route record; the target must belong to it |
| V4 Access control | 4.3.1 admin interfaces with MFA | Done (see V2) |
| V4 Access control | privilege escalation via impersonation | Done: never other platform admins; no nesting; no API, so no device or token as the target; exit restores only for the impersonated target |
| V7 Logging | 7.1.3/7.2.1 security events logged with who, what, why | Done: `platform.impersonation_started/ended` (operator, target, organization, reason, times, ended_by), `platform.admin_granted/revoked` |
| V7 Logging | 7.1.1 no secrets in logs | Done: health exceptions show only the class name; Gotenberg URL without credentials; audit redactor applies |
| V8 Data protection | 8.3.x minimal exposure | Partial: operators see organization names, members' names and emails (needed for support); no submission data on `/platform` |
| V13 API | 13.1.x API protected against session misuse | Done: impersonated sessions get 403 on `/api/v1` |
| V14 Config | 14.4.x security headers | Inherited from ADR 0040 (panel CSP, HSTS, frame-ancestors) |
