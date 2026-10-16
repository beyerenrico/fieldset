# State: 2026-10-16T09:30Z
Milestone: M0 Foundation · Task: M0-T01 Laravel 13 skeleton + quality tooling
Status: bootstrap done (ADRs 0001–0015, PLAN, memory files). M0 not started.
Branch: main → create `m0-foundation` next · Last commit: see `git log -1`

## Next actions
1. `git checkout -b m0-foundation`
2. M0-T01: install laravel/laravel ^13 into the repo root (keep docs/, spec/, AGENTS.md), Pest 5,
   Larastan, Pint, Rector; Postgres test DB via Docker (host port 54329)
3. M0-T02: Dockerfile + compose.yaml

## Files in flight
- none

## Known problems
- Host has its own Postgres on 5432 and Redis on 6379 (brew). Do NOT use them; use Docker services
  on non-default host ports (see LEARNINGS #1).

## Tried and rejected
- none

## Resume commands
docker compose up -d postgres redis gotenberg mailpit
php artisan test --compact
