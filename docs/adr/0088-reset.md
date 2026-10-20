# 0088: `fsa reset`
- Status: accepted
- Date: 2026-10-20
- Refines: ADR 0084 (safety backups, ledger, chain epoch), ADR 0085 (restore primitives), ADR 0081 (demo data and
  passwords), ADR 0072 (`fsa status`), ADR 0066 (exit codes, journal), ADR 0038 (audit chain heads)

## Context
Plan unit U23 (PLAN M12-T05): BUILD_PROMPT_V2 §7 (`reset`, `--demo`, `--no-backup`), R43, plan R57, R68, J5 (reset part),
KTD26. U16 (ADR 0084) gave the safety backup, the ledger and the chain epoch; U17 (ADR 0085) the quiesce, the gate that
goes down last, the Valkey flush and the fresh storage volume. Facts this relies on, checked 2026-10-20: `activity_log`'s
append-only trigger refuses `TRUNCATE` and every `DELETE` but retention pruning (migration
`2026_10_22_100000_harden_activity_log`), so emptying the database in place is not possible; Postgres 16
drops a database with open sessions with `DROP DATABASE … WITH (FORCE)`; the app's database user is the cluster's
superuser (it creates databases and the `pg_trgm` extension); Mailpit deletes every message on `DELETE /api/v1/messages`
without IDs; Docker copies the image's directory into an empty named volume the first time a container mounts it, also
into one made with `docker volume create` (J05: the fresh volume shows the image's layout, no other file).

## Decision
**Command (§7, R43).** `fsa reset [--demo] [--no-backup]` asks first; without a terminal and without `--yes` it is exit 6
and changes nothing. The answer is checked again under the lock (second review S4): a journal that may still delete
something (new, or open before its drop) needs it, so a journal that vanished between the question and the lock is no
silent yes. Like every mutating command it removes an expired or stopped quick tunnel first (ADR 0082). It runs under the
instance lock as the journaled operation `reset`, its flags as the journal's context; each phase is written before it
starts:

| phase | what | on failure |
|---|---|---|
| `plan` | Postgres and Valkey started if stopped; `instance:reset plan` (read-only one-off): organizations (soft-deleted ones count) and how many are not the demo, the oldest real organization, the active platform operators oldest grant first; the subject of the fresh instance (below) | exit 3 `owner_unknown`/`organization_unknown`, exit 4 when the database cannot be read; nothing changed |
| `quiesce` | `RestoreStack::quiesce()` (gate up, app, scheduler and backup stopped, the queue drained, `audit:seal`), the gate relabelled `reset` | way back: exit 5 |
| `ledger` | `audit:verify --heads` of the old database appended to `backups/ledger.log` (action, host UID, journal, version, `demo`, `no_backup`, epoch, heads, intact); and, read once no writer runs, the old database's sequence values and organization counts (`instance:reset plan`) as the phase's data | exit 5 |
| `safety_backup` | `SafetyBackup::take($state, 'reset', $lock)`, unless `--no-backup` | exit 5 |
| `audit_export` | with `--no-backup`: by the ledger phase's organization counts (no writer runs then); while a real one exists, `instance:audit-export` streamed into `backups/audit-export-reset-<UTC>.jsonl` (`.partial` first, 0600, renamed once its header checks out and it holds as many entries as the header names), else `skipped: only_demo_organizations` | exit 5, the partial file removed |
| `recreate_database` | every writer stopped (RestoreHalt::WRITERS), then `instance:reset recreate` from the maintenance database: the database DB_DATABASE names dropped `WITH (FORCE)` and created again, empty, under the same name and owner (never `postgres` or a template); repeated on a resume until `epoch` completed | the first attempt, when the old database still answers the plan query: the note `drop_not_reached`, then the way back (nothing was lost); else forward only (below) |
| `migrate` | the migrations, then the backup service's role (`instance:backup-role`), as install's migrate phase; repeated after every recreate | |
| `epoch` | `instance:reset epoch`: every sequence set above the old database's value (`SequenceAdvancer::raise`, second review S1), then `ChainEpoch::begin('reset', seed, old heads)` seeded with the higher of the ledger's highest epoch and the old database's, then `instance.reset` (CLI actor, host UID, both epochs, the old heads, the safety backup or the audit export, `demo`) as the first entry of the new epoch, one transaction; then the state's history entry `reset` | |
| `storage` | a fresh, labelled, empty volume `<project>_app-storage-reset-<journal id>`; the state's `storage_volume` names it and everything is rendered again | |
| `flush` | Valkey (`RestoreStack::flushValkey`, `VALKEY_EXTRA_FLAGS` emptied) and the mail catcher (`DELETE /api/v1/messages`, login on curl's stdin) | Valkey: exit 4 `valkey_not_flushed`, the gate stays up, the next run repeats it (S3); the catcher: warning `catcher_not_flushed` |
| `seed` | install's seed phase: `app:install` with the subject's organization, owner e-mail and name, locale and `--owner-link` (`AppCommands::install`, the values through install's own grammar); `app:install` is one transaction (A7) | |
| `verify` | the gate raised with a new token, the app alone started, the internal smoke test with the token (`RestoreStack::startAndVerify`) | |
| `open` | every service started, still behind the gate | |
| `demo`, `demo_rotate` | `--demo`: `DemoData::provide()` with `reissue`, so the passwords follow R29 (random whenever the instance is reachable beyond loopback, ADR 0081) | |
| `gate` | the gate goes down | |
| `cleanup` | the instance's storage volumes but the fresh one (the replaced one, older ones an incomplete restore or reset left) and the databases a restore or drill left (`DatabaseAdmin::DROPPABLE`), listed afresh each time (ResetLeftovers, A4/S5, A6) | warning `reset_cleanup_incomplete` |

Every way the reset closes seals the safety backup when encryption is on (`safety_backup_not_sealed` as a warning
otherwise). The result names the URL, mode, organization, owner e-mail, `safety_backup` or `audit_export`, both epochs,
the storage volume and what was flushed; the owner's sign-in link (60 minutes, or a fresh 15-minute `fsa login-link`
when the seeding happened in an earlier, interrupted attempt) and the demo passwords leave only as secrets (R50).

**Who the fresh instance belongs to (E9).** The install record keeps the organization and locale as plain values but the
owner's e-mail and name only as keyed digests (ADR 0072), so the plan reads the active platform operators from the old
database: the one whose e-mail matches the install record's digest (the digest is exact: an e-mail typed with capitals
at install does not match its lowercased account and the next rule applies), else the operator granted first. No active
operator is exit 3 `owner_unknown` with the hint `fsa config set operator-email`. The organization and locale come from
the install record, else from the oldest real organization (an instance installed `--from-backup` records neither); a
name install's grammar refuses is left out (install then uses the e-mail's local part). The owner's e-mail and name stay
in the open journal as a note (`subject`), because a reset resumed after the drop has nowhere else to read them; the
archived journal keeps their digests only (as install does).

**Before the drop: the way back (second review A1, A2).** A failure, or a signal (SIGTERM, SIGINT, SIGHUP; recorded and
raised at the next phase boundary, Interruption), from `quiesce` to a first drop that never reached the database takes the
way back of ResetWayBack, built like restore's abort (ADR 0085, U17 M2/L4/L7): the phase `abort` is journaled before
anything is touched, with the cause as its start data; `RestoreStack::reopenPrevious()` starts the unchanged app, lowers
the gate once it is healthy and starts the rest (a service that does not start is the warning `services_degraded`):
exit 5 `rolled_back` with the cause's code (e.g. `safety_backup_failed`), the journal archived, nothing deleted. When the
app does not become healthy, every writer stops (RestoreHalt::WRITERS: app, queue, scheduler, backup), the gate stays up
and the journal open: exit 8 `reset_rollback_failed`. A run that finds `abort` begun (an exit 8, or a way back that was
killed) only finishes it (exit 5), never resumes forward over writes the reopened instance took, and asks nothing.
`fsa reset --abandon` takes the same way back on request for any interrupted reset before its drop (closing the journal
with `ok`, no question: it deletes nothing); after the drop it is exit 2 `reset_past_drop`, the hint naming `fsa reset`.
A resumed run before the drop that finds a writer running takes the ledger line, the safety backup or the export again
(`writers_ran` in the quiesce phase's data): what the writers wrote after the earlier ledger line must not be lost and the
heads must be final (KTD26). A ledger line that was written stays (the ledger is append-only and read by its highest
epoch).

**From the drop on: forward only.** The data is gone once `recreate_database` began and reached the database (a first
attempt that failed while the old database still answered is noted `drop_not_reached` and counts as before the drop for
the question, `--abandon` and `fsa status`, A3). The old data is in the safety backup; with `--no-backup` only the audit
log is kept, or nothing when only demo organizations existed. A failure leaves the gate up
(the app answers 503) and the journal open: exit 4 (the cause's code when it is not a before-change code), and the hint
names the command and where the old data is. Signals are only recorded: the reset finishes. The next `fsa reset` with the
same flags continues from the journal without asking (nothing more can be lost). Until the epoch began the new database
holds nothing but the migrations, so `recreate_database` and `migrate` run again (no dead end when something wrote into
it); from then on a phase that completed is not repeated, `flush` runs once, and `verify` and `open` run again until
lowering the gate began. The epoch is not idempotent (LEARNINGS 138), so it is a phase of its own and `instance:reset
epoch` itself is repeatable: an epoch row next to an `instance.reset` entry is an earlier attempt's (answered
`began: false`), and a database with other audit entries is not the reset's fresh one (`database_not_fresh`). A first
attempt of `recreate_database` that fails while the old database still answers the plan query never reached it: the way
back runs (exit 5) instead. `app:install` answers
`already_installed` on a repeat. A resume with other flags is exit 9 `operation_unfinished` naming the recorded command
(`fsa reset --demo`, …).

**The audit export (R43).** Without a safety backup the ledger alone would be the record of what was there, so `--no-backup`
still writes the audit log while any real organization exists (soft-deleted ones included: their trail is still there),
counted after the quiesce so that no organization created meanwhile is missed; a demo-only instance keeps nothing. JSON Lines: a header `{format: "fsa-audit-export", version: "1.0", exported_at,
epoch, epochs, heads, entries}`, then every `activity_log` row as stored, by ID (properties were redacted when written; the
chain columns let the heads be checked against the rows). The writers are stopped and the log sealed before it runs. It
is no backup: BackupStore never lists it, retention never deletes it, `fsa uninstall --keep-backups` keeps it.

**IDs are never handed out twice (second review S1).** Signed links name row IDs and stay valid across a reset, because
APP_KEY stays: an invitation (`invitations/{membership}`, 7 days) lets its holder set the password of the invited account,
a data-export link names an organization slug and an export ID. The ledger phase records every sequence's last value of
the old database (`pg_sequences`, schema `public`); the epoch step raises each sequence of the new database at least to it
before it writes anything (the activity log's included, so `instance.reset` gets an ID after the old ones), reusing
U17's SequenceAdvancer (`values()`, `raise()`). An old link then names no row (404). The submission number counters
(`number_sequences`) start empty with the new forms, which get new IDs too.

**What stays (R43).** Settings and secrets: the env files are not touched, and of the state only `storage_volume` changes
(plus the history entry `reset`). Webhooks are not paused after a reset (ADR 0085 pauses them after a restore because
restored runs could repeat deliveries; a reset keeps no run), and a `webhooks_paused` setting stays as it was. The state
history before the reset stays too, but data recorded there is stale: `DemoData::recorded()` (repeated `fsa install
--demo`, R13) counts only `demo_seeded` entries after the last `reset` (amending ADR 0081).

**Status (R45).** `fsa status` reports `last_reset` (time, journal, epoch, safety backup or audit export, demo) and
`reset.interrupted` (last phase, `database_recreated`, `going_back`, safety backup or export, the command that resumes it,
`fsa reset --abandon` while it applies); the `operation_unfinished` warning and exit 9 of other commands name `fsa reset`
with its flags, and the hint follows the phase it stopped in (A9): no gate before the quiesce, 503 from then until the gate
went down, a running instance afterwards (only the clean-up is left), going back. `fsa backup` refuses an unfinished
operation like every other command (exit 9, A8): it checks the journal under the lock. The backup daemon cannot see the
journal (its container mounts only `locks/` and `backups/`); it is stopped by the quiesce, stays stopped while the
journal is open before `open` (and by the exit-8 halt), so it never backs up a half-reset database.

**What fsa never does.** The socket-holding process never opens a database connection (KTD25): plan, recreate, epoch,
export, migrate, backup role and seed are socket-less one-offs of the app service. No secret reaches an argument: the
catcher login goes to curl on stdin, the backup role's password by name in the environment.

## Alternatives considered
- **A scratch database swapped in after verification (like restore):** keeps the old data until the fresh instance is
  verified, but needs the whole way back of ADR 0085 for a database that is empty by intent; the safety backup keeps the
  data, and the plan asks for a recreated database.
- **Emptying the storage volume in place:** a `rm -rf` in a container meets files of other owners and loses them before
  the fresh instance passed its checks; a fresh volume costs nothing and the old one goes only in `cleanup`.
- **Owner flags on reset (`--owner-email`):** every prompt would need one and the agent guide a recipe; the operators in
  the database and the install record answer it, and exit 3 names how to add an operator.
- **Resuming from any `fsa` command:** the plan says "on the next `fsa` command". Other commands would have to finish a
  destructive operation they were not asked for, with its secrets (the owner link, demo passwords) in their result;
  instead every other command that changes the instance answers exit 9 naming `fsa reset`, `fsa status` shows it, and
  `fsa reset` continues without a question. Recorded as a deviation for review.

## Review
An independent review of the diff (2026-10-20) found no critical or high issues. Its three medium findings changed this
ADR in place: the database is recreated again until the epoch began (no `database_not_fresh` dead end), the export
decision is taken after the quiesce, and a first drop that never reached the database goes back (exit 5). Of the low
findings, the export is also checked against the header's entry count and synced before the drop.

## Second review (2026-10-20)
Security and adversarial reviews of 6fd10ae..2946e53, decided as above: S1 (sequence floors), A1 (writers stopped on exit
8 and before every drop; heads, backup and export again when writers ran), A2 (journaled way back, `--abandon`), A3/S2
(`drop_not_reached`), S3 (Valkey flush required), A4/S5 (leftovers removed), S4 (the yes checked under the lock), A6
(a repeated clean-up lists what is still there), A7 (`app:install` in one transaction, amending ADR 0072), A8 (`fsa
backup` exit 9, amending ADR 0084), A9 (hints by phase). A5 (restoring a reset's safety backup afterwards) is decided in
ADR 0085's carry: a source whose chain shows a reset after the backup is carry mode `reset`, nothing is carried and the
result warns `carry_skipped_after_reset`; the sequence floors stop the ID collisions besides.

## Verification review (2026-10-20)
- V1: a retake is decided by the journal, not by the live containers alone: the quiesce records `writers_ran` in its start
  data (a killed quiesce keeps it), and ledger, safety backup and audit export run again until an attempt of each
  completed after the last quiesce that found writers running. A kill inside a retaken backup is retaken by the next run.
- V2 (R57): every safety backup the journal took is sealed when the reset closes (ResetResult::safetyBackups), also one a
  retake superseded; none is deleted (safety backups are never pruned, and the older one may be the only copy of data
  written before the writers ran again).
- V3: the `drop_not_reached` note names its drop attempt; a later attempt counts as the drop.
- V4: `--abandon` checks the journal again under the lock (a journal that went meanwhile is exit 2 `nothing_to_abandon`,
  one past its drop exit 2 `reset_past_drop`); `fsa reset` was covered by the S4 check already.
- V5: a leftover `<db>_restore_<id>` goes with `fsa_restore_<id>`, `restore_drill_<id>` with `fsa_drill_<id>`.
- V6: sequence floors are filtered to names and values the epoch step accepts before the drop (the plan and the ledger
  phase), so no resume past the drop is refused on them.
- V7: `app:install` forgets Spatie's permission cache before its transaction and after a failure of it.
- V8: the volume the reset replaced is removed by name even without the instance label.
- V9: `fsa login-link` takes the instance lock and refuses while any operation journal is open (exit 9
  `operation_unfinished`): between the runs of a reset or restore it would write into the database being replaced.

## Consequences
- A reset interrupted after the drop leaves the instance answering 503 until `fsa reset` runs again; one interrupted
  before it stays behind the gate until `fsa reset --yes` or `fsa reset --abandon`.
- A resumed reset before the drop that finds writers running takes another safety backup; the earlier one stays (never
  pruned).
- Docker's data root may be another file system than the instance directory; the safety backup's space check (ADR
  0084) covers the instance directory only.
- The owner of the fresh instance is an operator of the old one; a reset of an instance whose install owner lost the
  operator role goes to the oldest remaining operator.
- Mail queued for the old data is gone with Valkey; mail the demo seeding sends lands in the emptied catcher.

## Amendment 2026-10-20: the audit export under encryption (M12 gate S5)
`fsa reset --no-backup` streams the audit export into `staging/audit-export-reset-<UTC>.jsonl.partial` (never plaintext
in `backups/`); once its header checks out it is encrypted to the backup recipients
(`backups/audit-export-reset-<UTC>.jsonl.age`, 0600, synced, then renamed) when the instance has any, else moved into
`backups/` as before. `--audit-export=` and `data.audit_export` name the file as written (with `.age` when encrypted).

## Amendment 2026-10-20: sealing and refusals (M12 gate, fix round B)
Closing a reset also seals the plaintext `safety-reset-*` sets made since its journal began (C3: one a killed run wrote
before the journal named it). `fsa reset` reports another unfinished operation, or a reset journal with other flags,
before its question (exit 9, also with `--yes`, C4), and exit 6 carries `demo`, `no_backup` and `safety_backup` (O6).
The writers check of a resumed reset moved to RestoreStack::writersRunning and JournalRecord::completedSince, which restore
and rollback share (D7).
