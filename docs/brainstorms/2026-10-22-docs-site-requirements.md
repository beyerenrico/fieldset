---
date: 2026-10-22
topic: docs-site
---

# Fieldset docs site on GitHub Pages

## Summary

A versioned documentation site at `https://beyerenrico.github.io/fieldset/`, built with VitePress on every release.
Each minor version gets its own copy of the guides, the API docs with an interactive reference, and the changelog.
A short product home page and the spec at its fixed `$id` URL sit outside the versions.

---

## Problem Frame

Fieldset's documentation is plain Markdown in `docs/guide/` (ADR 0042). It reads well on GitHub and in a clone, but there
is no site and no search beyond the Git host's. 2.1.0 is the first public release; `v2.1.0-rc.1` was pushed on
2026-10-22.

The spec's meta-schema `$id`, and the `$schema` of every bundled template, already point to
`https://beyerenrico.github.io/fieldset/spec/form-definition/1.0/meta-schema.json`. That URL returns 404 until a site
serves it (ADR 0092). The full API reference exists only on a running server, for admins (`viewApiDocs` gate), so
integrators cannot browse it without installing Fieldset.

---

## Key Decisions

- **VitePress.** Chosen over MkDocs Material with mike and over GitHub's built-in Jekyll. MkDocs needs a Python
  toolchain, and Material has been in maintenance mode since November 2025 per its author. Jekyll has no real search.
  VitePress fits the repo's Node and Vite toolchain, renders plain Markdown, and fails the build on dead links. The
  version switcher and the root redirect are in-house work.
- **One copy per minor version, kept forever.** 2.1, 2.2 and so on; a patch such as 2.1.1 replaces its minor's copy.
  Latest-only would lose history; per-patch copies would fill the switcher with near-identical entries.
- **Copies follow tags only.** A minor's copy goes live with its first release candidate. Doc fixes go live with the
  next patch release. There is no copy from `main` and no manual republish from `main`, so every copy matches a tag and
  never describes unreleased features.
- **Plain Markdown stays the source.** The guides keep ADR 0042's writing rules: relative links, no front matter, no
  generator-specific syntax. GitHub, clones and `tests/Unit/Docs/GuideReferencesTest.php` keep working. Site-only pages
  such as the home page live outside `docs/guide/`.
- **Spec and home page are unversioned.** The spec carries its own version (form-definition 1.0), independent of the
  product version, so its path never moves. The home page always describes the newest release.
- **A new ADR supersedes ADR 0042** for the generator and hosting decision and keeps its writing rules.

---

## Requirements

**Content**

- R1. Each version copy contains every guide in `docs/guide/`, the 1.0 archive included, rendered from the Markdown at
  that release's tag.
- R2. Each version copy contains the hand-written API docs (`docs/guide/api.md`, `docs/api/field-app-v1.md`) and an
  interactive OpenAPI reference exported from that release's code.
- R3. Each version copy contains the changelog as of that release.
- R4. The site renders the spec README and changelog for form-definition 1.0.
- R5. `https://beyerenrico.github.io/fieldset/spec/form-definition/1.0/meta-schema.json` returns the meta-schema JSON,
  identical to the repository file, whatever product versions exist. A later spec version gets its own path beside it.

**Versions**

- R6. Every minor version has its own copy under its own path. A version switcher on every page lists all copies and
  marks pre-releases.
- R7. A copy built from a release candidate shows a pre-release notice on every page until the minor's final release
  replaces it.
- R8. `/latest/` and the home page's docs links open the newest final release. While only a pre-release exists, as with
  2.1 today, they open that.
- R9. Publishing one version never changes or removes another version's copy.
- R10. Versions before 2.1 get no copy; they were never published.
- R20. A copy older than the newest final release shows a "newer version available" notice.
- R21. A pre-release never replaces a final copy; alpha and beta tags get no copy.

**Home page**

- R11. A site-only home page at the root shows a one-line pitch, the install command for the newest release, a few key
  features with screenshots, and links into the guides, API docs, spec and changelog.
- R12. Screenshots show the product under the Fieldset name. The existing milestone screenshots predate the rename and
  are retaken.
- R23. Screenshots use the English UI, matching the English docs.

**Links and quality**

- R13. Links between guides, and from guides to the API docs, spec and changelog, work on GitHub and on the site.
- R14. Links to content that is not on the site (ADRs, plans, README, security policy) open the file on GitHub.
- R15. A dead link fails the site build. Every CI run builds the site, so a release never meets a broken docs build first.
- R16. Full-text search covers the copy being viewed.
- R17. The generator, theme and plugins pass the project's license check (BUILD_PROMPT §11).
- R22. The public API reference cannot send requests, so nobody pastes tokens into a github.io page.

**Publishing**

- R18. A successful release run publishes its minor's copy to GitHub Pages. A publish can be re-run for the same tag
  without cutting a new release.
- R19. The 2.1 copy goes live once from the 2.1 release candidate, whose tag predates the site, after its release run
  succeeds. `v2.1.0` then replaces it.

---

## Acceptance Examples

- AE1. **Covers R7, R8.** Given only `v2.1.0-rc.1` is published, when a visitor follows the home page's docs link or
  opens `/latest/`, the 2.1 copy opens with a pre-release notice.
- AE2. **Covers R6, R8, R9.** Given `v2.1.0` and `v2.2.0-rc.1` are published, when a visitor opens `/latest/`, the 2.1
  copy opens; the switcher lists 2.2 (pre-release) and 2.1, and the 2.1 copy is unchanged by the 2.2 publish.
- AE3. **Covers R1, R9.** Given a guide typo was fixed on `main` after `v2.1.0`, when `v2.1.1` is tagged, the 2.1 copy
  shows the fix and no other copy changes. Before that tag, the site still shows the typo.
- AE4. **Covers R5.** When a JSON Schema validator fetches the meta-schema `$id`, it receives the meta-schema JSON, also
  after 2.2 is published.

---

## Scope Boundaries

- German docs. The guides stay English and quote German UI terms as the UI shows them (ADR 0042).
- A "next" copy built from `main`, and manual republishing from `main`.
- ADRs, plans, agent memory files and the README as site pages.
- A custom domain. The spec `$id` is bound to the Pages URL, which the rename plan accepted.
- Copies for versions before 2.1, and pruning old copies.

---

## Dependencies / Assumptions

- GitHub Pages must be enabled for the repository with GitHub Actions as the source. This is a repository settings
  change.
- The repository stays at `beyerenrico/fieldset`. Moving it would leave the spec `$id` stale (accepted in the rename
  plan).
- VitePress `2.0.0-alpha.20`, pinned exactly (human decision): 1.6.x depends on Vite 5, which is out of support with an
  unfixed high advisory that fails the audit gate; the 2.0 alpha uses Vite 8 like the rest of the repo.
- Each `actions/deploy-pages` deploy replaces the whole site (deploy-pages issue #50).

---

## Sources

- `docs/adr/0042-documentation-site.md` (plain Markdown, rejected generator, "a new ADR supersedes this one")
- `docs/adr/0092-rename-to-fieldset.md:51` and `docs/plans/2026-10-21-001-refactor-rename-to-fieldset-plan.md:47`
  (the `$id` resolves once the docs site serves `spec/`)
- `tests/Unit/Docs/GuideReferencesTest.php:139` (relative links must exist)
- `config/scramble.php:159` and `app/Providers/AppServiceProvider.php:88` (`viewApiDocs` gate);
  `vendor/dedoc/scramble/src/Console/Commands/ExportDocumentation.php:20` (`scramble:export`)
- VitePress routing and site config: https://vitepress.dev/guide/routing, https://vitepress.dev/reference/site-config
- Material for MkDocs maintenance mode: https://squidfunk.github.io/mkdocs-material/blog/2025/11/05/zensical/
- mike: https://github.com/jimporter/mike; deploy-pages: https://github.com/actions/deploy-pages
- In-VitePress OpenAPI rendering: https://github.com/enzonotario/vitepress-theme-openapi
