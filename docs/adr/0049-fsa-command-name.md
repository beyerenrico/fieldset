# 0049: The management command is `fsa`
- Status: accepted
- Date: 2026-10-17

## Context
E3. Owners type the management command, and agents call it many times per task. It has to be short, stable and
predictable for both.

## Decision
- `fsa` is a shim script in the instance directory (`<dir>/fsa`). It starts the ops container (ADR 0048).
  `install.sh` offers to link it into the `PATH`. In the image, `fsa` is a wrapper over the `fsa:*` artisan
  commands.
- Command set per §7: install, doctor, status, logs, config show/set, login-link, demo, reset, upgrade, rollback,
  backup, restore, tunnel, adopt, uninstall, version. Every command takes `--json`, `--yes` and `--dir`.
- Output contract (plan KTD3):
  - One final JSON document on stdout with `schema_version`, `command`, `status`, `changed`, `data`, `secrets`,
    `warnings` and `errors`.
  - With `--json`, NDJSON progress events go to stderr.
  - `--secrets-file` writes one-time secrets to a 0600 file instead of stdout.
- Exit codes are those in §7 (0, 2–7, 10) plus two from the plan: **8**, rollback failed and the instance is
  stopped (R55), and **9**, aborted before any change with the previous services still running (R69). §7 allows
  changes only through an ADR, and this ADR is that record.
- The shim (plan U4):
  - reads the ops image digest from the instance state;
  - mounts the instance directory at the same path and writes a nonce file that the container must see;
  - mounts the parent directory of every file-path flag;
  - opens the `open_url` from the JSON result when one is present.
- The `PATH` link is optional and never replaces an existing file of that name (decided here, in line with
  "re-running is safe", §2.4).

## Alternatives considered
- **`formctl`:** clearer, but longer to type for a command that people and agents run constantly.
- **`docker compose exec app php artisan …`:** needs a running app container and the right project, so it cannot
  install or upgrade.

## Consequences
- Each instance has its own shim, and the `PATH` link points at one of them. Managing many instances from one
  place is a non-goal (§4.2).
- The agent guide (R51) documents the envelope and the exit codes 0, 2–10 as a stable contract.
- Unverified: whether any common distribution ships another `fsa` binary. The non-replacing link covers that case.
