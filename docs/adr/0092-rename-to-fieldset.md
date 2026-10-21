# 0092: Rename form-schema-app to Fieldset (release 2.1.0)
- Status: accepted
- Date: 2026-10-21
- Supersedes: the product, CLI and format names in every earlier ADR (their bodies keep the old names as history)
- Plan: `docs/plans/2026-10-21-001-refactor-rename-to-fieldset-plan.md` (units U1–U8)

## Context
`form-schema-app` was a working title (QUESTIONS 1). It was baked into the signer identity, the image name, every
installer and every instance's Docker resources, and none of these can change after a public release without breaking
installed instances (QUESTIONS 25). Nothing was published: `v2.0.0` exists only as a local tag.

The human decided (QUESTIONS "Human decisions (2026-10-21)"):

> 1, 25: renamed to **Fieldset** before the first publish: repository `beyerenrico/fieldset`, CLI `fieldset` (was `fsa`);
> signer identity, `install.sh`, images and docs change with it. Trademark not yet checked (EUIPO/USPTO).

> Rename details: no 1.0 users besides the human, so 1.0 phone storage and SSO TXT names are renamed without fallback;
> spec `$id` on GitHub Pages (`beyerenrico.github.io/fieldset/spec/…`); release 2.1.0.

## Decision
Rename everything current-facing in one release, 2.1.0, without compatibility shims. One mechanical mapping per family:

| Old | New |
|---|---|
| `form-schema-app` (product, repository, image, dev Compose project) | `fieldset` (`beyerenrico/fieldset`, `ghcr.io/beyerenrico/fieldset`) |
| Form Schema / `FormSchema\` / `packages/form-schema-{php,js,react}` | Fieldset / `Fieldset\` / `packages/fieldset-{php,js,react}` (`fieldset/fieldset-php`) |
| `@form-schema-app/*` npm workspaces | `@fieldset/*` |
| CLI `fsa`, artisan `fsa:*`, shell functions `fsa_*` | `fieldset`, `fieldset:*`, `fieldset_*` |
| `FSA_*` environment variables | `FIELDSET_*` |
| Docker labels `app.form-schema.*` | `app.fieldset.*` |
| Instance Compose projects `fsa-<slug>-<hex>`, marker `.fsa-instance` | `fieldset-<slug>-<hex>`, `.fieldset-instance` |
| Test resources `fsa-test-*` | `fieldset-test-*` |
| Databases `form_schema*`, roles and work databases `fsa_*` | `fieldset*`, `fieldset_*` |
| Persisted formats: backup `fsa-backup`, HKDF info, audit and security exports, local CA name, browser storage, preview messages | the same names with `fieldset` |
| SSO TXT record `_form-schema-app-verification` / `form-schema-app-verification=<token>` | `_fieldset-verification` / `fieldset-verification=<token>` |
| Spec `$id` `https://localhost/spec/form-definition/1.0/meta-schema.json` | `https://beyerenrico.github.io/fieldset/spec/form-definition/1.0/meta-schema.json` |
| Template registry `urn:form-schema-app:bundled-templates` | `urn:fieldset:bundled-templates` |

- The format keeps version 1.0: the rename changes names and the `$id`, not semantics (spec CHANGELOG).
- `release.json` `upgrades_from` is `2.1.0-alpha.1`; the harness test versions moved to the `2.1.0-*-test` series.
- Not renamed (plan R6): ADR bodies, existing agent memory entries, milestone reports and evidence, plans, release notes,
  the body of `docs/guide/archive/` (only its redirect banners changed), CHANGELOG entries before 2.1.0, and the 1.0
  adoption defaults (`LegacyCheckout::DEFAULT_PROJECT = 'form-schema-app'`, `LegacyCheckout::DEFAULT_DATABASE =
  'form_schema'`, `tests/Support/Docker/V1Install.php`), because `fieldset adopt` must still find a 1.0 checkout.

## Consequences
- **No compatibility** for anything written under the old names: 1.0 Field App phone storage (IndexedDB and local
  storage names; unsynced phone data must be synced before switching), SSO domains verified with the old TXT record
  (re-verify with `_fieldset-verification`), backups in the `fsa-backup` format (rejected as an unknown format), 2.0
  pre-release instances, devices and backups. Accepted because only the human runs 1.0 and nothing 2.x was published.
- The spec `$id` resolves only once the GitHub Pages docs site serves `spec/` (follow-up plan). Moving the repository
  later would leave the `$id` stale; accepted by the human.
- The dev stack is a new Compose project `fieldset` with new, empty volumes (LEARNINGS 163); the old project's volumes
  and images stay for the human to remove (QUESTIONS 77).
- Human steps before any publish (RELEASING.md "Pre-publish checklist", QUESTIONS 77): trademark check for "Fieldset";
  rename the GitHub repository to `beyerenrico/fieldset` **before any tag push**, otherwise the release is signed under
  the old identity and every installer rejects it; push only the `v2.1.0` tag by name, never `--tags` (the local
  `v2.0.0` must stay unpublished); first GHCR publish as before.
- Old names remain greppable only under the R6 paths; `GuideReferencesTest` fails a guide that names a former `fsa`
  command.
