# Contributing

Thanks for helping! A few rules keep the project healthy.

## Rules reviewers enforce
1. **Tests first.** Every change comes with Pest (PHP) or Vitest (JS) tests. Never weaken, skip or delete a test, or
   lower a gate, to get green.
2. **Conventional commits** (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`).
3. **Small files.** Under 400 lines (800 at most), functions under 50 lines, nesting up to 4 levels.
4. **Spec changes** update `spec/form-definition/<version>/README.md`, its `CHANGELOG.md`, the meta-schema and
   `spec/conformance/` in the same commit.
5. **Developer Certificate of Origin.** Sign off your commits (`git commit -s`). The application is
   AGPL-3.0; the spec and SDK packages are MIT.
6. **No secrets** in the repository. Keep `.env.example` current.

Architecture decisions live in `docs/adr/`. Propose new ones as ADRs in your pull request.

## Checks
Start the dev services first (`docker compose up -d postgres redis gotenberg mailpit`; see the README's Development
section). CI runs each of these:

| Check | Command |
|---|---|
| Formatting | `vendor/bin/pint --test` |
| Static analysis | `composer analyse` (Larastan, also for the schema) |
| Rector | `vendor/bin/rector --dry-run --no-progress-bar` (must report 0 files) |
| PHP suite | `DB_DATABASE=fieldset_test php artisan test --compact --parallel` |
| JS unit tests | `npm run test` |
| JS types and lint | `npm run typecheck` and `npm run lint` |
| Conformance (PHP and JS) | `npm run conformance` |
| E2E (Playwright) | `npm run e2e` against the Docker stack, `docker compose up -d --build --wait` first |
| Docs site | `(cd docs/site && npm ci && npm test)`; it is its own package |

For shell, Dockerfile and workflow changes also run the linters in Docker:

```bash
docker run --rm -v "$PWD:/mnt:ro" -w /mnt koalaman/shellcheck:v0.11.0 docker/app/entrypoint.sh docker/app/fieldset distribution/*.sh
docker run --rm -i hadolint/hadolint:v2.15.1 hadolint - < docker/app/Dockerfile
docker run --rm -v "$PWD:/repo:ro" -w /repo rhysd/actionlint:1.7.12 -color
```

The install journeys (`vendor/bin/pest --testsuite=Install`) run real Docker, create resources named `fieldset-test-…`
and take long. A change to `app/Domain/Instance`, `distribution/` or `docker/` should run at least
`vendor/bin/pest --testsuite=Install tests/Install/Journeys/J01LocalInstallTest.php`. Touch only Docker resources you
created.
