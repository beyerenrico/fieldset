# AGENTS.md — Fieldset

Autonomous build, now at v2. Specs (never edit): `docs/agent/BUILD_PROMPT_V2.md` (2.0: Docker
distribution; its CLI `fsa` is now `fieldset`) and `docs/agent/BUILD_PROMPT.md` (product spec for everything 2.0 does not
change). Implementation plan: `docs/plans/2026-10-17-001-feat-docker-distribution-v2-plan.md`
(units U1–U23, KTDs, test scenarios); the rename to Fieldset (2.1.0):
`docs/plans/2026-10-21-001-refactor-rename-to-fieldset-plan.md`. No human is available: never ask, never wait. Decide per
spec §0/§15, record ADRs in `docs/adr/`, log human-review items in `docs/agent/QUESTIONS.md`.

## Restart protocol (every new session / after compaction) — BUILD_PROMPT_V2 §15.3
1. Read this file, then `docs/agent/STATE.md`, then `docs/agent/PLAN.md`.
2. Read the last 5 entries of `docs/agent/JOURNAL.md`, then `docs/agent/LEARNINGS.md`.
3. `git status` and `git log --oneline -15`. Uncommitted changes: finish or discard per STATE.md;
   if STATE.md does not describe them, save them on a branch first.
4. Remove leftover Docker resources of an interrupted journey: names contain `fieldset-test-` (see `fieldset` below), so
   find them with `docker ps -a --filter name=fieldset-test-` (substring; same for `volume ls`, `network ls`) or by
   the label `app.fieldset.instance`.
5. Start services, run the fast suite, confirm STATE.md matches reality (fix STATE.md if not).
6. Re-read the BUILD_PROMPT_V2 sections and plan units the current task depends on.
7. Continue with the first "Next actions" item in STATE.md.

## Memory files (§15.2)
- `docs/agent/PLAN.md` — tasks + checkboxes (single record of progress)
- `docs/agent/STATE.md` — handover, overwritten, ≤60 lines, updated every commit
- `docs/agent/JOURNAL.md` — append-only, ≤10 lines/entry
- `docs/agent/LEARNINGS.md` — gotchas; check before retrying a fix
- `docs/agent/QUESTIONS.md` — human review later; never blocks
- `docs/agent/milestones/Mx.md` — gate reports; evidence in `milestones/evidence/Mx/`

## Key commands
```
docker compose up -d postgres redis gotenberg mailpit   # dev services, Compose project `fieldset` (host ports in .env.example)
DB_DATABASE=fieldset_test php artisan test --compact --parallel   # full PHP suite (Pest)
php artisan test --compact --filter=Name                 # focused
vendor/bin/phpstan analyse --memory-limit=1G             # Larastan
vendor/bin/pint --test                                   # formatting
vendor/bin/rector --dry-run --no-progress-bar            # must report 0 files
npm run test -w packages/fieldset-js                     # JS unit tests
npm run conformance                                      # PHP + JS conformance runners
npm run -w resources/field-app build                     # Field App build
npm run e2e                                              # Playwright (browsers in storage/playwright)
vendor/bin/pest --testsuite=Install                      # install journeys (real Docker, names with fieldset-test-)
vendor/bin/pest --testsuite=Install tests/Install/Journeys/DogfoodE2ETest.php   # E2E suite against a fieldset instance (ADR 0091)
docker run --rm -v "$PWD:/mnt:ro" -w /mnt koalaman/shellcheck:v0.11.0 docker/app/entrypoint.sh docker/app/fieldset distribution/*.sh
docker run --rm -i hadolint/hadolint:v2.15.1 hadolint - < docker/app/Dockerfile
docker run --rm -v "$PWD:/repo:ro" -w /repo rhysd/actionlint:1.7.12 -color
```
Long output → `storage/agent-logs/` (git-ignored); read only the tail.

## `fieldset` (2.0)
- `fieldset:*` artisan commands in `app/Domain/Instance/Console`, run in a throwaway ops container of the
  app image (Docker socket + instance dir at the same absolute path). `install.sh` and the shim live in
  `distribution/`. Docker is reached only through `DockerGateway`; tests use
  `tests/Support/Instance/RecordingDockerGateway.php`.
- Test images, harness projects (`fieldset-test-harness-<id>`) and instance directories are named `fieldset-test-…`.
  Instance Compose projects are `fieldset-<slug>-<hex>`, so a test instance's containers, networks and volumes are
  `fieldset-fieldset-test-<dir>-<hex>…`, labelled `app.fieldset.instance=<uuid>`. Journeys remove them after each
  run. Never touch Docker resources you did not create (except the dev stack). Never push a `v*` tag.

## Conventions
- Domain code in `app/Domain/<Module>`; Filament/API/jobs stay thin and call Actions.
- Every tenant-owned model uses `BelongsToOrganization`; the scope throws without a tenant.
  Platform code opts out with `withoutTenant()`. Never `withoutGlobalScopes()` in tenant code.
- Spec semantics change ⇒ update `spec/form-definition/1.0/{README,CHANGELOG,meta-schema.json}`
  and `spec/conformance/` in the same commit.
- Money/number submission values are decimal strings; never floats.
- TDD with Pest; files <400 lines (max 800); functions <50 lines; nesting ≤4.
- Conventional commits. One branch per milestone (`m9-release`, `m10-modes`, `m11-mail-phones`,
  `m12-lifecycle`, `m13-release`; the rename is `rename-fieldset`, tag `rename-done`); merge `--no-ff` to `main`
  after the gate, tag `mX-done`.
  Never force-push or rewrite `main`.
- Commit trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Never weaken, skip or delete a test or lower a gate to get green.
- Stay inside this repo and its Docker services. No global config changes, no real external
  accounts (ACME, tunnels, registries): use the local substitutes in BUILD_PROMPT_V2 §11.
