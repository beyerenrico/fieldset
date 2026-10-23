# 0094: Versioned docs site on GitHub Pages, built with VitePress
- Status: accepted
- Date: 2026-10-22
- Supersedes: ADR 0042's generator and hosting decision; its writing rules are carried forward
- Plan: `docs/plans/2026-10-22-001-feat-docs-site-plan.md` (units U1–U13); requirements:
  `docs/brainstorms/2026-10-22-docs-site-requirements.md`

## Context
ADR 0042 kept the guides as plain Markdown without a generator. 2.1.0 is the first public release, and three gaps
remain: no search beyond GitHub's, no public API reference (Scramble serves one only to admins of a running server), and
the spec `$id` `https://beyerenrico.github.io/fieldset/spec/form-definition/1.0/meta-schema.json` returns 404 until a
site serves it (ADR 0092).

Constraints found while planning:
- `release.yml` cannot host a Pages deploy: `ReleaseWorkflowTest` allows `id-token: write` only in `image` and `sign`
  and forbids `workflow_dispatch`. An extra OIDC job there would mint tokens under the installer's signer identity
  (ADR 0060).
- Each `actions/deploy-pages` deploy replaces the whole site (deploy-pages issue #50).
- VitePress 1.6.4 depends on `vite ^5.4.14`. `npm audit --audit-level=high` on a fresh 1.6.4 lockfile fails on high
  advisories in `vite <=6.4.3` (GHSA-fx2h-pf6j-xcff, GHSA-587h-7wmj-h5pm) with no fix available (checked 2026-10-22).

## Decision
**Generator.** VitePress `2.0.0-alpha.20`, pinned exactly. The human chose the alpha over 1.6 (requirements,
"Dependencies / Assumptions"); it uses Vite 8 like the rest of the repo. Beside it, `github-slugger` `2.0.0` gives
GitHub-compatible anchors and `@scalar/api-reference` `1.75.0` renders the interactive reference. The Scalar page is
vendored from the lockfile-checked `node_modules` at build time: never committed, never loaded from a CDN (ADR 0040
lists CDN scripts without SRI as an open gap).

**Package.** `docs/site/` is its own npm package with its own `package-lock.json` and `.npmrc` (`ignore-scripts=true`,
`audit=true`; npm reads project config only from the nearest package root). It is not in the root workspaces, the root
lockfile or the app image (`.dockerignore` allows no `docs/`). The CI audit job runs `npm audit --audit-level=high` and
`node scripts/check-licenses.mjs docs/site` for it.

**Hosting.** GitHub Pages at `https://beyerenrico.github.io/fieldset/`, deployed with `actions/deploy-pages`.

**Publishing.**
- A separate workflow, `.github/workflows/docs.yml`, runs on `workflow_run` (Release, completed) and
  `workflow_dispatch`. `release.yml` stays unchanged.
- A `resolve` job proves the release before anything builds: event `push`, path `.github/workflows/release.yml`, same
  repository, conclusion `success`, tag commit equal to `head_sha`, and `cosign verify-blob` of the tag's
  `release-manifest.json` with the installer's identity checks.
- Jobs split by privilege. `build` (contents: read) runs npm, VitePress and the released app image, so its output is
  untrusted. `store` and `deploy` run only git, jq, grep, cmp and pinned actions, and verify that output.
- The tooling comes from `main` and the content from the tag (a worktree), so tags that predate the site can publish.
  There is no copy from `main`.

**Storage.** An append-only branch `docs-site` holds the complete deployable site, with one normal commit per publish
and no force-push. A bad publish is undone with `git revert`. Old copies cannot be rebuilt, so the stored tree is their
only copy. Rejected: rebuilding every minor per deploy (re-renders old copies with new tooling, and one broken tag
blocks every publish); release assets (`sign` compares assets byte for byte, and root parts have no tag); squashed
history (deletes the only backup).

**Versions.** One copy per minor, from 2.1 on. An rc copy is kept until the minor's final replaces it. Alpha and beta
tags get no copy. The decision table is in the plan.

**Permanent public contract**, from the first live copy:
- URLs: `/fieldset/` (home), `/fieldset/<minor>/` (guide index), `/fieldset/<minor>/<guide>` and
  `/fieldset/<minor>/archive/<page>` (clean URLs), `/fieldset/<minor>/field-app-api-v1`, `/fieldset/<minor>/changelog`,
  `/fieldset/<minor>/api-reference.html`, `/fieldset/spec/form-definition/1.0/` with its raw JSON files beside it,
  `/fieldset/spec/form-definition/1.0/changelog`, `/fieldset/latest/`. `dev` is reserved for working-tree builds and
  is never published.
- `/fieldset/versions.json` schema 1, frozen and additive only: `schema`, `latest` (a minor), and `copies[]` with
  `minor`, `version`, `tag`, `prerelease`, `builtWith`. Readers ignore unknown fields and fall back to baked values for
  `schema` above 1. The origin is shared with every `beyerenrico.github.io` site, so readers treat the file as hostile:
  they build URLs only from minors matching `^\d+\.\d+$` under `/fieldset/` and render labels as text.

**Writing rules kept from ADR 0042:**
- Only relative links between documents.
- No HTML, no front matter and no generator-specific syntax. The site renders content pages as text rather than Vue, and
  staging rejects front matter, `@include`, `<<<` imports, `<script>` and `<style>`.
- Every command, environment variable, path and setting must exist in the code; German UI terms are quoted with an
  English gloss.

Sources stay readable on GitHub. Links are rewritten only in the rendered site, and the agent guide stays byte-identical
because `sign` compares it as a release asset.

## Consequences
- Search, versioned copies and a public reference page. A meta CSP (`connect-src 'none'`) stops it from sending
  requests.
- The `$id` resolves once the 2.1 copy is live. It depends on the URL never moving:
  - A custom domain on this repository, or on a user site (it applies to all project sites by default, per the GitHub
    Pages docs), moves the site off `beyerenrico.github.io`. Whether the old URL redirects, and whether validators
    follow, is unverified.
  - A renamed account changes the host (inferred: the host is the account name).
  - A `beyerenrico.github.io` repository could register a service worker on the shared origin and intercept `/fieldset/`
    requests in browsers that visited it (inferred; server-side validators are unaffected).
- An alpha generator: config and slot APIs may change. Upgrades are deliberate, and CI rebuilds the newest published tag
  of each minor with the current tooling.
- A second lockfile to audit and license-check. A dependency that fails either gate blocks the docs like any other.
- Docs appear only after a successful release run, about 2.5 hours after the tag (plan estimate).
- Every clone carries the `docs-site` branch of built HTML, which grows by about one copy per publish (inferred).
- Repository settings need the human (plan U10): Pages from GitHub Actions, environments `github-pages` and `docs-store`
  limited to `main`, and a ruleset that blocks force-push and deletion of `docs-site`.
