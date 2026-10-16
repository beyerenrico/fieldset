# 0031: Render failure records, sweep, exactly-once mail and pinned SMTP connections
- Status: accepted (amends 0028 and 0029)
- Date: 2026-10-16

## Context
The M3 review found that permanent render failures were only logged (waiting "send to customer"
requests hung forever, the Field App kept polling `document_not_ready`), lost dispatches were never
retried, overlap releases used up render attempts, a re-run render job could create a second rendition,
a retried or re-delivered mail could be sent twice and carried expired links, and the SMTP SSRF guard
had the DNS-rebinding gap that ADR 0029 accepted.

## Decision
- **Render failure record:** `rendition_failures` (one row per submission: reason `budget_exceeded` |
  `render_failed`, scrubbed message, attempts, failed_at). `GenerateRendition::failed()` writes it
  (RecordRenditionFailure, per-submission advisory lock), audits `submission.pdf_failed` and fires
  `RenditionFailed` after commit; the Mail module fails waiting sends with `document_failed`. The API
  answers **409 `document_failed`** with a fixed text when no rendition exists; the inbox shows the
  failure. A new rendition or a new submit deletes the record. Admin notifications were not added; the
  inbox is the office's view of the problem.
- **Retry policy:** `GenerateRendition` uses `retryUntil` (30 min) + `MaxExceptions(3)` +
  `CountCrashesAsExceptions`, so `WithoutOverlapping` releases do not count as attempts but crashes do.
  Each job carries a `request_id` stored on the rendition (unique), so a re-run of the same payload
  returns the rendition it created (no second file, audit entry or event).
- **Sweep:** `documents:sweep` (every five minutes, one server) re-dispatches renders of non-draft,
  non-void submissions older than `documents.sweep.after_minutes` without a rendition since their latest
  submit and without a failure record (cool-down per submission; batch limit), and fails waiting sends of
  voided submissions (`submission_void`).
- **Mail exactly-once (as far as the log can tell):** status `sending`; `DeliverMail` claims
  queued→sending with one conditional update, a transport error releases it to queued, an attempt that
  finds `sending` (worker died mid-send) marks it failed for a manual retry instead of resending;
  `RetryMail` claims failed→queued conditionally. Message-ID `<mail-{id}@{app host}>` on every attempt;
  timeout 60 s below `retry_after`; "sent" and `document.sent` commit together. Document links are
  extended to now + TTL before every attempt. Customer mail of a voided submission is refused at delivery.
- **SMTP pinning (closes the gap of ADR 0029):** `SmtpHostGuard::pinnedAddress()` resolves once,
  validates every address and the port (25/465/587/2525 unless `allow_private_hosts`), and the transport
  connects to that IP while TLS verification and SNI use the configured host name (`ssl.peer_name` on
  Symfony's SocketStream). Changing host, port, user name or encryption requires the password again.
- **Error texts:** stored errors pass `ErrorText::scrub()` (secrets, URL credentials, key=value secrets,
  app path, 500 characters); users see fixed translated texts per reason code.

## Consequences
The scheduler must run (`scheduler` service). A mail whose worker died mid-send needs a human decision
(retry or not) instead of a possible duplicate. Render workers hold at most one ≤25 MP photo in memory
at a time (~110 MB); uploads are stored at ≤25 MP, so PHP's 512 MB `memory_limit` suffices. The CI
Gotenberg service cannot take `--chromium-disable-javascript` (GitHub service containers have no
command); compose.yaml sets it.
