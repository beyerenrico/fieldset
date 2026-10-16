# State: 2026-10-16T11:30Z
Milestone: M0 Foundation · Task: M0-T16 Members/Groups/Roles resources + policies + invitations
Status: tenancy, auth (shared MFA), API base, audit, i18n, roles matrix, isolation harness done
and green (149 tests). Docker image builds. CI, app:install, docs, admin resources pending.
Branch: m0-foundation · Last commit: see `git log -1`

## Next actions
1. M0-T16: Filament resources Members (invite, roles, groups, deactivate), Groups, Roles (capability
   checkbox list); policies per model using `User::hasPermissionIn()`; invitation mail + accept page
2. M0-T13: `app:install` command + DemoSeeder (owner, designer, reviewer, worker, coordinator)
3. M0-T14: LICENSE files, README, SECURITY.md, CONTRIBUTING.md
4. M0-T03: .github/workflows/ci.yml; M0-T02: verify `docker compose up` app + /up
5. M0-T15 gate

## Files in flight
- none (all committed)

## Known problems
- None open. See LEARNINGS #6 (PendingDispatch destruct timing inside TenantContext::run).

## Tried and rejected
- Filament Shield: per-resource permissions don't match §8.6 capability rows (ADR 0017)

## Resume commands
docker compose up -d postgres redis gotenberg mailpit
php artisan test --compact
vendor/bin/phpstan analyse --memory-limit=1G --no-progress
