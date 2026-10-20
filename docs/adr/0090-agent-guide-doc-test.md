# 0090: Agent guide, its doc-test and the 2.0 guides
- Status: accepted
- Date: 2026-10-20
- Refines: ADR 0042 (plain Markdown guides, "everything named exists"), ADR 0066 (envelope, exit codes)

## Context
Plan unit U21 (PLAN M13-T02): R51 asks for an agent guide with recipes (exact commands, JSON fields, failure handling)
whose command blocks CI executes against the local release (J11); R52 for 2.0 guides with the checkout-based
instructions in an archive. ADR 0042 forbids HTML and generator syntax in the guides, so the marker for "this block
runs" must be plain Markdown. Not every recipe can run locally: a VPS install needs public DNS and Let's Encrypt, a
phone demo a phone, a domain change DNS; the harness release is a pre-release, which never lands in
`releases/latest/download`. QUESTIONS 69 (O5): the recovery fields differ between envelopes (`data.next` is a command for
upgrade and rollback, a kind for restore; exit 9 carries `data.command`).

## Decision
- **Markers.** A runnable block is a fenced block with the info string `sh runnable`; the next fenced block must be
  `json expect`: an object with an integer `exit_code` and any envelope fields. Placeholders (`"<string>"`, `"<int>"`,
  `"<bool>"`, `"<list>"`, `"<object>"`, `"<any>"`) match any value of that type; other values match exactly; fields left
  out are not checked. GitHub renders both as ordinary highlighted blocks (the first word of the info string), and
  readers see the expected envelope. Blocks marked only `sh` are documentation and are not executed.
- **What J11 changes (tests/Support/Docs/GuideCommand.php), and nothing else.** The guide shows the real one-liner and
  real paths; J11 replaces the one-liner with the harness release's `install.sh --version <harness version>`,
  `~/forms…` with `fsa-test-j11-forms…` in its work directory (so every Docker resource is `fsa-test-`), other `~/`
  paths with the work directory, `smtp.example.com --smtp-port 587` with a stand-in Mailpit on the instance network
  (plain SMTP on 1025), and the placeholders `<backup>`, `<backup-path>` (from the last `fsa backup` result) and
  `<version>` (the harness version). It provides the two files the guide tells the person to create (SMTP password,
  backup passphrase). Blocks run in document order against one instance, plus a second one for the move recipe.
- **Runnable recipes:** local demo, sign-in links, SMTP, backup, drill, restore, webhooks on, upgrade (`--to` the
  installed version: `current: true`), move to another server (encrypted backup with secrets, install from it,
  uninstall the old one). Not runnable: VPS install, phone demo, domain change (each needs DNS, ACME, a phone or the
  internet); their flags are still checked by the scan below.
- **Scan (tests/Unit/Docs/GuideReferencesTest.php, fast suite).** For README.md and every file in `docs/guide/`
  (archive included): each `fsa <command>` and its flags must be a registered `fsa:*` command and option (`--version` is
  the entry point's alias of install's and adopt's `--release`); each install.sh flag must be one install.sh handles or
  `fsa install` takes; each `php artisan` command must exist; each upper-case environment variable name in code must
  appear in the code (app, config, distribution, docker, scripts, .env examples, compose.yaml); each relative link must
  resolve. Only code (fenced blocks, inline code spans) is scanned. The one exception: api.md's receiver example
  (the integrator's own secret variable and PHP's `HTTP_*` request headers).
- **O5 (QUESTIONS 69): documented, not changed.** The agent guide's "recovery object" section gives one reading order
  across envelopes (exit 9 `data.command`; exit 5 `retry_after`/`fix_first`/`command`; exit 8 `errors[0].hint` plus
  `data.next` per command, `data.recovery` for config and tunnel; `data.safety_backup`). Unifying the envelope fields
  would change the agent contract of four operations shortly before 2.0.0, for no new capability.
- **Archive.** The 1.0 guides move unchanged (links fixed) to `docs/guide/archive/1.0-*.md`, each with a note that it
  is for checkout installs and that its `git`, `docker compose`, migration, restore and `.env` steps must not be used on
  an `fsa` instance (M12 docs review). The new guides name `fsa` commands only.

## Consequences
- A guide change that breaks a recipe fails J11; a guide that names a removed flag or variable fails the fast suite.
- J11 costs two full installs and a restore (minutes); it runs with the other install journeys.
- The guide's expectations are deliberately loose (types for values that vary per run), so J11 checks the contract
  agents rely on, not incidental values.
- The release workflow ships `agent-guide.md` as an asset once it exists (already wired in `release.yml`); the local
  harness does not add it, so `LocalPreReleaseTest`'s asset list stays as it is.
