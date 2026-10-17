# 0042: Documentation site: plain Markdown in `docs/guide/`
- Status: accepted
- Date: 2026-10-18

## Context
§13 M8 asks for a "docs site" with install and upgrade guides, and §11 Operability asks for an upgrade
guide plus documented backups and restore drills. The readers are:
- self-hosters, who install and upgrade from a clone of the repository, often on a server without a
  browser;
- office administrators and field workers, who need a user guide;
- integrators, who need the API reference.

The repository already keeps all of its documentation as Markdown: the README, ADRs, the API contract
(`docs/api/field-app-v1.md`), release notes and the spec (`spec/`). GitHub and Gitea render Markdown,
relative links and tables without any build step. The live API reference is generated at runtime by
Scramble (`/docs/api`), so it needs no site generator either.

Options considered:
1. **Plain Markdown in `docs/guide/`** with an `index.md` table of contents and relative links.
2. **A static site generator** such as MkDocs (Material), VitePress or Docusaurus, published to GitHub
   Pages.

## Decision
Option 1. The guides live in `docs/guide/*.md`, with `docs/guide/index.md` as the entry page. The README
links to the index under "Documentation".

Rules:
- Only relative links between documents, so the guides work offline, in a clone and on any Git host.
- No HTML, no front matter and no generator-specific syntax (admonitions, tabs, includes).
- Every command, environment variable, path and setting in a guide must exist in the code at the time
  of writing. Planned features are marked "planned" or left out.
- German UI terms appear in quotes as the UI shows them, with an English gloss.

We do not add a generator because it would bring a new toolchain (Python for MkDocs, or another Node
dependency tree for VitePress or Docusaurus), a CI job and a hosting decision, but no new content. A
generator's search and navigation help a large manual; ours has about ten pages. The project's license
rules (§11 License hygiene) would also apply to the generator and its theme.

## Consequences
- No build step and no new dependencies; the guides are reviewed in the same pull request as the code
  they describe.
- There is no full-text search beyond the Git host's search. The index page is the navigation.
- Links can break silently. Check them before a release, for example by resolving every relative
  `](…)` target in `docs/guide/` against the file system.
- Adopting MkDocs or VitePress later stays cheap, because both read plain Markdown with relative links.
  If that happens, a new ADR supersedes this one.
