# Upgrade guide

An instance installed with `install.sh` upgrades with one command. `fsa` backs up first, checks the new version
behind a maintenance gate and rolls back by itself when it fails. Do not upgrade an instance with `git pull`,
`docker compose pull` or a manual `php artisan migrate`: that bypasses the safety backup and the rollback, and the
next `fsa` command would not know which version runs.

A 1.0 installation from a clone of the repository first moves to 2.0 with [`fsa adopt`](install.md#adopt-a-10-install);
the 1.0 upgrade steps are in the [archive](archive/1.0-upgrade.md). Agents: see the
[agent guide](agent-guide.md#recipe-upgrade).

## Upgrade

```sh
fsa upgrade                 # to the newest stable release
fsa upgrade --to 2.1.0      # to a named version (also a pre-release)
fsa version                 # the running version
```

Read the release notes of every version you skip; the result names them (`notes_url`). A new major version is
refused (exit 7 `major_upgrade`) until you add `--major` or name it with `--to`. A version that cannot be reached in one
step is refused with the versions to go through (exit 7 `upgrade_path`); a downgrade is refused too (use
`fsa rollback`).

What `fsa upgrade` does, in order (each step is journaled, so an interrupted upgrade resumes or rolls back cleanly):

1. Downloads the target's release manifest, verifies its signature against the identity built into the image, and
   checks the upgrade path, Docker, the platform, the registry and the free disk space. Then it pulls every image by
   digest. Nothing has changed so far.
2. Raises the maintenance gate (the app answers 503 to everyone), stops the scheduler and the backup service, lets the
   queue finish its jobs (up to 5 minutes), waits for requests in flight and seals the audit log.
3. Takes a safety backup (`safety-upgrade-<time>` in `backups/`) and verifies it.
4. Migrates the database with the new version, starts the new version behind the gate and runs a smoke test.
5. Commits: the instance now runs the new version. The gate goes down, workers start, and older upgrade safety
   backups are removed.

If step 4 fails, `fsa` restores the safety backup into the previous version and exits 5 with the new version's log
lines (`data.logs`). The instance then runs exactly as before. Report the logs; do not retry blindly.

Flags:

- `--drop-jobs`: when queued jobs do not finish in time (exit 9 `drain_timeout`), drop them instead. Their payloads go
  to `operations/dropped-jobs-<id>.json`; delayed jobs stay.
- `--resume`: continue an interrupted upgrade (also printed by `fsa status`).
- `--follow`: the upgrade runs in a detached container, so closing the terminal does not stop it. `fsa upgrade
  --follow` attaches to it again, or shows the last upgrade's result.

## Roll back

```sh
fsa rollback
```

After a successful upgrade, `fsa rollback` returns to the previous version by restoring that upgrade's safety backup.
Everything written since the upgrade is lost; the confirmation question says how much (`loss`). Like a restore, it
starts a new audit chain epoch and leaves webhooks paused (`fsa config set webhooks on`). An encrypted safety backup
needs `--identity-file` and `--passphrase-file` (or `--passphrase-stdin`). It is refused (exit 7) when there is no
previous release or the instance was restored since the upgrade.

## Exit codes

| Exit | Meaning |
|---|---|
| 0 | Upgraded, or already at that version (`current: true`) |
| 3 | A check failed before anything changed (download, signature, disk space, backup service) |
| 4 | The new version is committed, but opening the gate failed: run `fsa upgrade --resume` |
| 5 | Rolled back automatically: the instance runs the previous version |
| 7 | Refused: major version, upgrade path, downgrade |
| 8 | The rollback failed too: the instance is stopped behind its gate. Run `fsa upgrade --resume` (the hint and `data.next` say so); the safety backup is in `data.safety_backup` |
| 9 | Nothing changed: queue did not drain, an operation is unfinished, the instance is locked |

## The Field App update

The Field App is a web app with a service worker. It does not update in the middle of someone's work:

1. After the upgrade, each device finds the new version the next time the app checks, for example when it is opened.
2. The app shows "Eine neue Version der App ist verfügbar." ("A new version of the app is available.").
3. "Jetzt aktualisieren" ("Update now") saves open drafts on the device, activates the new version and reloads.
   "Später" ("Later") keeps the old version until the next prompt.

Unsent work stays in the device's outbox during the update. If the app is open in several tabs, the other tabs ask to
reload ("Neu laden"). Devices stay registered.

## After the upgrade

```sh
fsa status          # version, health of every service
fsa doctor --post
fsa logs app --since 15m
```

Then sign in, open a submission and its PDF, and open the Field App on a phone (accept the update prompt). Run a
restore drill (`fsa restore <backup> --drill`) on the first backup after the upgrade.

Platform operators also see the running version on the instance page of the `/platform` panel. When the instance's
update check is turned on, that page says when a newer release exists and shows the command to upgrade.
