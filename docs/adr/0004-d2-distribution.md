# 0004: D2 Distribution — self-hosted Docker, multi-tenant codebase
- Status: accepted
- Date: 2026-10-16

## Context
§0 D2 default. One codebase must serve self-hosters and a possible hosted free instance.

## Decision
Ship Docker Compose (`app`, `queue`, `scheduler`, `postgres`, `redis`, `gotenberg`, `mailpit` dev).
Row-level multi-tenancy by `organization_id` (§6.2). `php artisan app:install` bootstraps the
first organization. Self-service org registration is configurable (`APP_ALLOW_REGISTRATION`).

## Consequences
Every tenant table, job and command must carry tenant context; isolation tests are mandatory.
