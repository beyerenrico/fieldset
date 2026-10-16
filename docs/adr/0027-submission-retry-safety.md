# 0027: Retry safety of the submission API (idempotency, numbering, read model)
- Status: accepted
- Date: 2026-10-16

## Context
The M2 correctness review found that field networks (lost responses, double taps, concurrent retries)
could replay stale errors, shadow a successful submit with 409, or make the gap-free allocator issue an
existing number after a version changed its numbering format or reset rule.

## Decision
- `IdempotentRequest` runs the request in one transaction holding `pg_advisory_xact_lock(org, user,
  key)`; only 2xx responses are stored (raw body, 24 h, pruned hourly). A concurrent duplicate waits
  and replays; a failed attempt leaves no key, so the retry runs again.
- Submit and draft saves are naturally idempotent: repeating the last successful request (same user,
  same data, revision = stored − 1) returns 200 with the current submission.
- `NumberAllocator` keeps the §6.4 sequence key (org, form, period), serializes per form with an
  advisory lock and skips values whose rendered number already exists. Numbers stay unique and the
  issued series has no holes; a changed format continues the period's counter.
- `submission_values` reflects the last submitted data: a `changes_requested` submission keeps its
  projected rows (status updated) until it is resubmitted.

## Consequences
Events and jobs triggered inside a submit must use `DB::afterCommit` / `afterCommit()` (the outer
transaction belongs to the middleware). Rebuilding the read model from scratch skips
`changes_requested` submissions until resubmit (no copy of the last submitted data is kept) —
QUESTIONS #11.
