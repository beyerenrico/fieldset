# State: 2026-10-18T00:00Z
v2 run (BUILD_PROMPT_V2.md, plan `docs/plans/2026-10-17-001-feat-docker-distribution-v2-plan.md`), branch
`m9-release`. Done: M9-T01 bootstrap + ADRs 0047–0064, T02 U1 image (648 MB), T03 U2 instance core, T04 U3
compose renderer, T05 U6 sign-in links, T06 U7 release pipeline + harness. Last commit: `git log -1`.

## Next actions
1. M9-T07 U4 install.sh + shim + ManifestVerifier (in progress; ADR 0070 reserved).
2. M9-T08 U5 lifecycle commands (fsa install/doctor/status/logs/version/uninstall/login-link) + J1, J12.
   Consume U4's host-facts contract and U6's app-side commands (`app:install --owner-link --json`,
   `identity:login-link`, `identity:login-links`) through socket-less one-offs (KTD25).
3. M9-T09 gate: resolve the Trivy gate (QUESTIONS 27), J1+J12 on arm64 and amd64 (emulated), 75 E2E,
   reviewer subagents, M9.md, build 2.0.0-alpha.1 with the harness, merge, tag `m9-done`, break down M10.

## Decisions taken
- U7 is verified by building, signing and serving the harness release; the install through J1 belongs to U5.
- Release tooling commands are `release:manifest` / `release:version` (not fsa:*; ADR 0067).
- Compose file is JSON (valid YAML; no symfony/yaml). Valkey gets a password and a labelled volume (ADR 0069).

## Known problems
- Trivy gate red on upstream findings without fixed releases (QUESTIONS 27).
- E2E runs rewrite evidence PNGs: `git checkout docs/agent/milestones/evidence` before committing.
- Host Composer 2.8.12 fails `composer audit` on the ignored abandoned package; use the CI image.
- Dev image `form-schema-app:local` was rebuilt for U6 E2E (old image kept as `form-schema-app:pre-u6`).

## Resume commands
docker compose up -d --wait
DB_DATABASE=form_schema_test php artisan test --compact --parallel
vendor/bin/pest --testsuite=Install          # journeys, real Docker
npm run test
vendor/bin/rector --dry-run --no-progress-bar   # CI gate, must be 0 files
