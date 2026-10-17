# 0038: Audit log hardening (UI, append-only trigger, retention, hash chain)
- Status: accepted
- Date: 2026-10-17

## Context
§8.11 asks for an append-only audit log with a read-only Filament resource (filters, CSV export), a History
tab on records, configurable retention with a pruning job (365 days by default), optionally a database
trigger that blocks UPDATE and DELETE, optionally a hash chain, and the AC "every action in the list
produces exactly one entry; secrets never appear in diffs". Since M1 the log is spatie/laravel-activitylog
5.1 (`activity_log`, `App\Domain\Audit\Models\Activity` refuses updates and deletes through Eloquent;
EnrichActivity adds organization, actor type, IP, user agent and request id, and redacts secrets).

## Decision
- **Reading.** `AuditLogResource` (`/admin/{org}/audit-log`) is read-only and scoped explicitly to the
  current organization (`AuditLogQuery`; the table has no tenant global scope because entries are written
  from every context). Access: the existing `audit.view` capability (ADR 0017; Owner, Admin) via
  ActivityPolicy. Filters: actor (members or System), event, subject type, date range in the viewer's time
  zone; all filters are plain values (`AuditLogFilters`) shared by the table, the CSV download and the
  queued export.
- **CSV export.** Up to `audit.export.sync_max_rows` (2,000) entries download at once; more go to a queued
  `DataExport` (ADR 0039, the M5 pattern of ADR 0033: job in the tenant, private disk, notification with a
  signed link that expires with the file after `privacy.exports.keep_days`). One `audit.exported` entry per
  export. Cells use the M5 CSV writer (locale dialect, formula neutralization).
- **History.** A `RecordHistory` table widget is added at the end of the form, submission, workflow, members
  and SSO pages through a Filament render hook (`PanelsRenderHook::PAGE_END`, scoped to those pages), so the
  pages stay unchanged. `HistoryScope` defines a record's history: its own entries plus its parts (form →
  versions, submission → mails, workflow → runs); members (memberships, accounts, devices, with a member
  filter); SSO (provider, domains, claim mappings).
- **Append-only in the database.** A `BEFORE UPDATE OR DELETE` row trigger and a `BEFORE TRUNCATE` statement
  trigger on `activity_log` raise an exception, with two exceptions:
  1. DELETE when the transaction-local setting `app.audit_prune` is `on`. Only `audit_prune(cutoff)` sets it
     (`set_config(..., true)`), deletes and sets it `off` again in the same call, so the bypass never
     outlives the prune statement (also inside the test transaction, LEARNINGS #36b).
  2. UPDATE that only fills the hash chain columns of an unsealed entry (`hash IS NULL` → value; the row
     compared as `to_jsonb(...) - chain columns`, like the form_versions retire rule), done by `audit_seal()`.
  Both functions are `SECURITY DEFINER` with a fixed `search_path`, `EXECUTE` is revoked from `PUBLIC`, and
  `audit_prune()` refuses any cutoff younger than 30 days, whoever calls it.
  Scope of the protection: the trigger stops every application path (Eloquent, query builder, a stray
  `DELETE`). Any session can set the custom setting, and in the default Docker install the application role
  owns the table, so a database owner (or SQL injection as the owner) can still delete or disable triggers;
  against that the hash chain gives tamper *evidence*. **Hardened install** (documented for operators): a
  maintenance role owns `activity_log` and the three functions; the application role gets `SELECT, INSERT`
  on the table (and its sequence) and `EXECUTE` on `audit_seal()` and `audit_prune()` only. The setting then
  grants nothing (privileges are checked before the trigger), and pruning stays limited to entries older
  than 30 days.
- **Retention.** `audit:prune` (daily 03:15) deletes entries older than `audit.retention_days` (default 365,
  at least 30; read with a default in code; `--days` overrides) through `audit_prune()`. It only ever cuts a
  chain at its start: `audit_prunable(cutoff)` keeps a sealed entry while a later-created entry of its chain
  has a lower sequence number. The function returns, per chain, the last deleted position and hash; the
  delete and the platform entry `audit.pruned` (cutoff, count, anchors of every chain: the previous ones
  carried forward plus the new cuts) commit in **one transaction**, so a killed run never leaves a cut
  without its anchor, and a concurrent seal (which waits for nothing but sees the not-yet-deleted rows)
  always links to the entry the anchor names. Longer retention where the law requires it is a config value.
  spatie's `activitylog:clean` is not used (the trigger refuses it).
- **Hash chain (implemented).** One chain per organization, chain 0 for platform entries. An entry is
  sealed *after its transaction commits* (`DB::afterCommit` from `Activity::created`): in a short transaction
  holding `pg_advisory_xact_lock(830011, chain)` it reads the chain head and calls `audit_seal()` with
  `chain_seq = head + 1`, `prev_hash` and `hash = sha256(prev_hash, seq, stored values as text)`. Sealing in
  commit order means long business transactions never wait for each other (only the sub-millisecond sealing
  transactions queue per chain), and rolled-back entries never enter the chain; a lock taken inside the
  business transaction would have serialized every audited transaction of an organization and risked
  lock-order deadlocks with row locks (number sequences, submissions). A seal that did not run (crash
  between commit and seal) is caught up by `audit:seal` (every five minutes; one failing entry does not
  block the others). `audit:verify [--organization=] [--heads]` recomputes every chain: hash per entry,
  links, no gaps, and a start that is either seq 1 with the genesis hash or the cut recorded by the newest
  *sealed* `audit.pruned` entry. Entries older than ten minutes that are still unsealed are a failure too
  (exit code 1). The chain proves that existing entries were not changed or removed in the middle; it cannot
  prove that the newest entries (or a whole chain) were not removed, nor that a plausible entry was not
  inserted by someone with INSERT rights. For the former, `--heads` prints each chain's head (seq, hash) as
  JSON for the operator to ship to storage outside the database and compare on the next run.
- **Coverage (AC).** `tests/Feature/Audit/AuditCoverage*Test.php` run each §8.11 action through its domain
  action or endpoint and assert the exact list of entries it writes, plus the absence of every secret used.
  Gaps found and fixed: lockouts were never audited (Filament throttles without an event; Fortify's named
  limiter skips `EnsureLoginIsNotThrottled`): the admin login audits `auth.lockout` when it shows the
  throttle notice, Fortify's `login` limiter fires `Lockout`; both write one entry per throttle window,
  address and IP (`LockoutAudit`, email cut to 254 characters), so unauthenticated clients cannot flood the
  log; a wrong MFA code in the admin login now writes
  `auth.mfa_failed`; notification preference changes (`notification_preferences.updated`), test mails
  (`mail_settings.test_sent`) and webhook secret rotations (`secret` in the logged attributes, redacted) are
  audited. Duplicates removed: an invitation of a new address wrote `member.invited` and the account's
  `created`; deactivating an account wrote `user.deactivated` and the attribute diff; publishing a design
  with pending edits wrote `design.updated` and `design.published`. Facts that stay separate entries by
  design: a recovery-code sign-in (`auth.recovery_code_used` + `auth.login`), an approval followed by the
  system's completion, break-glass sign-ins (ADR 0036). Impersonation (`platform.impersonation_started` /
  `_ended`, M8-T06) is checked as a contract and skipped while the classes are absent.
- **System entries.** `AuditLogger::system()` writes entries without a causer (retention, pruning), so a job
  that runs synchronously inside a request is never attributed to the signed-in user.

## Consequences
Every audit write costs one extra short transaction after commit (sealing). Audit entries of erased users
keep their old values until retention removes them (ADR 0039). A hardened deployment can move table
ownership to a maintenance role; the default Docker setup does not. Attachment downloads are not in the
§8.11 list and stay unaudited.
