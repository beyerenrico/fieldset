# 0058: Health-gated upgrades with automatic rollback
- Status: accepted
- Date: 2026-10-17

## Context
E12 and §2.4 principle 3: an upgrade must never leave a broken instance behind, and migrations stay forward-only.
The plan refines E12 and R34 with KTD5 and KTD19 and adds R54, R55, R57, R62 and R69.

## Decision
- `fsa upgrade` stays within the installed major. `--to` picks a version, and `--major` is needed to cross a major;
  without it, or below the manifest's oldest upgrade source, `fsa` exits 7.
- Every upgrade runs under the instance lock, with a journal entry written before each step (R54). The supervisor
  is a detached ops container of the **installed** image (KTD5), and the foreground `fsa` tails the journal.
- The order follows KTD19, which supersedes R34's order:
  1. Doctor, including space for a safety backup, a second database and a second storage volume; verify the
     manifest signature.
  2. Pull all digests. 3. Raise the gate; stop app, scheduler and backup.
  4. Drain Horizon with a bounded wait, then stop the queue. On timeout exit 9, or with `--drop-jobs` save the
     dropped payloads and continue.
  5. Safety backup, verified by checksums and `pg_restore --list`.
  6. The target image's migrations in a child without the socket, with `lock_timeout` and liveness from
     `pg_stat_activity`. The child stages the target's compose and env files, and the supervisor validates them.
  7. Start the app from the staged files behind the gate, and recreate the other changed services except queue,
     scheduler and backup.
  8. Internal smoke test; no invalid index. 9. Commit (journal, state). 10. Lower the gate; start the rest.
- What happens on failure:
  - Before step 5: lower the gate, restart the previous services, exit 9 (R69).
  - From step 5 until the commit: restore the safety backup atomically, flush Valkey, re-render the previous
    compose file from the state (previous digests, current volume names), start, verify, lower the gate, exit 5
    (R35).
  - A failed rollback leaves the instance stopped: exit 8 with the backup path, both digest sets and the recovery
    steps (R55). After the commit nothing restores automatically.
- Upgrade migrations never use `CONCURRENTLY`, because writers are stopped. Safety backups are 0600, never
  pruned, and replaced only after a successful commit (R57).
- Manual `fsa rollback` (R37) states the data loss first and carries the R64 security state. It refuses after a
  restore or reset that happened since the upgrade.

## Alternatives considered
- **Manual rollback only:** leaves broken instances behind. **Zero-downtime upgrades:** a non-goal (§4.2).

## Consequences
- Downtime lasts through backup and migrations; the Field App sees 503 and retries. Upgrades need free space for
  a second database and storage volume (ADR 0062). Rollback starts a new audit chain epoch (KTD26, amends ADR 0038).
- Still open (plan, deferred review item): R62 promises 503 during the whole gate, but the app is stopped in steps
  3–6. U18 resolves it, with a separate gate responder or by restating R62 with local `/up` exempt.

## Amendment 2026-10-20: what U18 built (ADR 0086)
- The open question of R62 is answered without a separate responder: the installed app stays up behind its gate through
  steps 3–6 and answers 503 in every mode; step 7 replaces it, still gated (ADR 0086). Only the app runs during the gate.
- The supervisor's journal, the child, the drain and both rollbacks are specified in ADR 0086. The safety backup is
  verified by the restore's prepare step; the automatic rollback's ledger line takes the safety backup's heads.
- The manual rollback's R64 carry goes through a versioned security-state export of the newer release, and the previous
  image renders its own compose and env files for the current settings (not the preserved snapshot, which goes stale
  after `config set`); `previous` in the state still keeps the snapshot for the record.
