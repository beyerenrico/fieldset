# 0059: Daily backups inside the stack, with optional age encryption
- Status: accepted
- Date: 2026-10-17

## Context
E13. The 1.0 script dumps through `docker compose exec postgres`, which needs the socket, and the app image has no
Postgres client (Appendix C). Bookworm ships client 15, while the stack runs `postgres:16-alpine`. The plan
amends E13 with KTD15–KTD17, R57, R67 and R68.

## Decision
- Daily at 03:30 instance time, keep 7, in `<instance>/backups/`; `config set` changes all three (R32).
- A dedicated `backup` service of the app image runs them, without the socket (KTD15):
  - container root with every capability dropped except `DAC_READ_SEARCH`, `DAC_OVERRIDE`, `CHOWN` and `FOWNER`,
    because the private disk creates 0700 directories owned by `www-data`. Output is chowned to the owner UID.
  - Storage and state are mounted read-only and `backups/` read-write, plus the lock directory. It connects to
    Postgres as a read-only dump role (`pg_read_all_data`). The next run follows from the ledger's last success.
- Client: PGDG `postgresql-client-16`, 16.15-1.pgdg12+2 in `bookworm-pgdg` for amd64 and arm64
  (apt.postgresql.org, verified 2026-10-17), PostgreSQL License. pg_dump "cannot dump from PostgreSQL servers
  newer than its own major version" (postgresql.org/docs/16/app-pgdump, verified). A release check therefore fails
  when the image's `pg_dump` major is below the manifest's Postgres major (R39).
- Contents (KTD16): `database.dump` (custom format), `storage.tar.gz`, `settings.json`, `manifest.json`,
  `SHA256SUMS` and `audit-heads.json`, all from one exported snapshot (`pg_export_snapshot`, `pg_dump --snapshot`).
  An HMAC over `SHA256SUMS` uses a key derived from APP_KEY (R67). Restore refuses archives without a valid HMAC
  unless `--accept-unauthenticated` is given.
- `--encrypt` (KTD17):
  - Backups are encrypted to an age X25519 recipient, and only the public key lives in the instance.
  - The private key is protected by the owner's passphrase, written once to a path outside the instance directory,
    and test-decrypted at setup. Rotation adds a recipient.
  - Without a TTY, `age-plugin-batchpass` reads the passphrase from a file descriptor (`AGE_PASSPHRASE_FD`,
    age-plugin-batchpass(1), verified).
  - age is pinned at 1.3.x. 1.3.2 (2026-08-29) is current, its linux tarballs contain `age`, `age-keygen` and
    `age-plugin-batchpass` (verified), and the license is BSD-3-Clause. `--include-secrets` requires encryption.
- Results go to a `backup_runs` table. Failures, and skips older than 24 hours, alert operators in `fsa status` and
  `/platform` (R39). Retention prunes only scheduled backups after a successful run, never safety or manual
  backups or the newest verified one (R57).

## Alternatives considered
- **Weekly:** up to a week of data lost. **Offsite targets:** a non-goal (§4.2, Appendix B).
- **Through the socket or as a scheduler job:** the first gives a long-running service root, the second puts the
  backup mount into the app roles (KTD15).

## Consequences
- Backups stay on the host, and `/platform` says so; copying them off-host is the owner's job. Losing the identity
  file or its passphrase makes encrypted backups unreadable. The batchpass man page prefers native keys over
  scripted passphrases; here the passphrase only protects the identity file.
