# 0089: The `/platform` instance page, the update check and backup alerts
- Status: accepted
- Date: 2026-10-20
- Refines: ADR 0041 (/platform), ADR 0061 (update check off by default), ADR 0074 (runtime.json), ADR 0077 (`config set`),
  ADR 0084 (backups, BackupAlerts)

## Context
Plan unit U20 (PLAN M13-T01): BUILD_PROMPT_V2 R39 (failed or stale backups notify operators and stay visible as a
persistent warning), R47 (instance page), R48 and E15 (opt-in update check), R23 (catcher linked from /platform), R53
(DE and EN). The app may not load ops code (KTD24, `tests/Arch/InstanceBoundaryTest.php`): web and scheduler code see
the instance only through `App\Domain\Instance\Runtime`, and every other directory under `app/Domain/Instance` counts as
ops. Mode, digests, the catcher URL and the update-check setting live in the ops-side instance state; the app only knew
its own version (`APP_VERSION`), the mail config and `backup_runs`.

## Decision
**Runtime channel.** `runtime.json` gains two optional blocks, written by RuntimeWriter on every render from the state:
`instance` (`version`, `mode`, `digests` role => `repo@sha256:…`, `catcher_url`) and `updates` (`check`,
`manifest_url`). RuntimeDocument parses them leniently: a malformed block counts as absent (InstanceFacts null, update
check off) instead of failing the file, because neither is security-relevant and the strict rules exist for the URL and
the gate. The manifest URL must pass the runtime URL grammar (no credentials, query or fragment).

**`fsa config set update-check on|off`** (UpdateCheckApplier). It was the last key of R32 a later release brought, so
ConfigKeys::LATER, the `setting_not_available` refusal and the `arrives_with` entries of `config show` are gone (ADR
0077's deferred-key rule has served its purpose). `on` stores the settings
`update_check: true` and `update_url` = `ReleaseFeed::base(facts)/latest/download/release-manifest.json`, i.e. the
same release URL `fsa upgrade` uses (FSA_RELEASE_URL, https unless a harness build; else GitHub Releases), computed in
`plan` so `on` again with an unchanged URL is `changed: false`. Only runtime.json changes; no service is recreated.
`config show` reports the key and lists the URL as the `update_check` contact while on.

**Update check** (`App\Domain\Platform\Instance\Updates\UpdateCheck`, not `App\Domain\Instance\Updates` as the plan
named it: that namespace is ops and the scheduler and the page may not reach it). `instance:check-updates` is scheduled
daily at 04:17 with `->when(enabled)`; the command itself returns without a request when off, so off means no HTTP call
on any path. On, one GET with 10 s connect / 30 s total timeouts, redirects at most 5 and https-only for an https URL,
no identifier, body at most 1 MiB. Only `version` (semantic version) and `notes_url` are read; the outcome (time, newest
version, notes URL, error code `unreachable` / `http_<status>` / `invalid_manifest`) is kept in the cache forever; a
failed run keeps the newest version seen before. A cache flush only empties the section until the next run. The notes
URL becomes a link only when it is https and contains no quote, space or angle bracket. Version precedence follows
SemVer 2.0 (UpdateStatus::compare); the manifest's signature is not verified here: it is display data, and `fsa upgrade`
verifies on its own (ADR 0061).

**Instance page** (`App\Filament\Platform\Pages\Instance`, `/platform/instance`, operators only like Health). Read on
each load by InstanceOverview; problems first in plan order: backup alerts (BackupAlerts codes, severity as the health
page's BackupCheck, command as the `fsa status` hint: `fsa backup`, `fsa logs backup`, `fsa doctor --post`, `fsa status`),
`backup_silent` (an fsa instance without any `backup_runs` row), disk (< 10 % free warns, < 5 % danger, `fsa status`),
update available (`fsa upgrade`), and the note that backups are held only on this host (fsa cannot know about off-host
copies, so it shows whenever a backup succeeded). Then version and digests, mode and URL, mail with the catcher link
(and `fsa config show --catcher-login` in the loopback modes), backups, disk and updates. Without a runtime file no
`fsa` command is shown.

**Backup alerts** (BackupAlertNotifier, `instance:backup-alerts`, hourly). Every alert of BackupRuns::summary is mailed
once per incident to every active operator in their locale (BackupAlertNotification, queued, `error` level, the command
and the page link). An incident is the alert code plus the `started_at` of the run it is about, remembered 30 days in the
cache (`Cache::add`); a new failure, or the same alert after a success, is a new incident. Without an active operator
nothing is remembered. `backup_silent` is shown but not mailed: right after an install the service may not have reported
yet, and a false alarm by mail would teach operators to ignore them.

## Alternatives considered
- **Mode and digests as app env variables:** a change would need the app, queue and scheduler recreated; runtime.json is
  already the per-request channel and changes without restarts.
- **A database table for the update status or notified alerts:** durable across a Valkey flush, but a migration for data
  that the next run recreates; the cost of a flush is at most one repeated mail or one empty day.
- **The organization Notifier (in-app inbox):** operators need not belong to an organization; mail reaches them, and the
  page carries the persistent warning.

## Consequences
- `fsa version` still reports `newest_known: null`: the ops container cannot read the app's cache.
- An older app reading a newer runtime.json ignores the two blocks (unknown keys are ignored).
- The page passes axe in the E2E run (U22 runs the E2E suite; not run in this unit).
