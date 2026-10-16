# 0029: Mail delivery, document links and "send to customer"
- Status: accepted
- Date: 2026-10-16

## Context
§8.8 asks for queued, branded, per-organization mail with a `mail_messages` log, retry of failed mail,
an optional per-organization SMTP server, a From address on the instance domain, and PDFs as attachments
up to a size limit or as expiring links (default 14 days, 410 when expired). §9.2 adds "send to
customer", which may be requested before the PDF exists.

## Decision
- **Own job instead of `Mail::queue`:** `QueueMail` writes the `mail_messages` row (status `queued`,
  the serialized mailable encrypted in `payload`) and dispatches `DeliverMail(message id)` after commit.
  The job restores the tenant, sends through `OrganizationMailer`, and records `sent` / the error / `failed`
  after the last try. A retry resends exactly the stored mailable. `Mail::queue` would give neither the log
  row nor a retry with the original content, and would pick the transport at dispatch time.
- **Sender:** sender name and reply-to per organization; the From address is `mail.from.address` unless
  the organization's own SMTP server is used, then its configured From address (deliverability, no spoofing
  of the instance domain).
- **SMTP SSRF guard:** an organization SMTP host must resolve only to globally routable addresses
  (`SmtpHostGuard`, checked on save and before every send; `messaging.smtp.allow_private_hosts` for
  development). Accepted gap: DNS rebinding between the check and the transport's own lookup (TOCTOU) is
  not closed; pinning the resolved IP would break TLS host verification. Revisit with an egress proxy.
- **Document links:** a random 48-character token in the URL; only its sha256 is stored
  (`document_links.token_hash`), so a database leak yields no working links. Laravel signed URLs were
  rejected: they cannot be revoked or counted, and their validity is tied to `APP_KEY`. Expired links answer
  410 until the row is pruned 90 days after expiry (`messaging.links.prune_after_days`), then 404.
- **Attachment or link:** `DocumentDelivery` attaches documents while the total stays within
  `messaging.attachment_max_bytes` (10 MB), the rest become links. The audit entry `document.sent` is
  written by `DeliverMail` when the mail was actually sent.
- **Send to customer:** `document_sends` records each request (`waiting_for_document` → `queued`, or
  `failed`). `RequestCustomerSend` takes the per-submission rendition advisory lock, so a rendition that
  commits concurrently is either seen (mail queued at once) or announces itself afterwards: `RenderSubmission`
  fires `RenditionCreated` after commit, and the queued listener `SendWaitingDocuments` mails the waiting
  requests (own retries; marks them failed after the last try). The mail uses a localized default
  template (organization locale) with the form name, number, organization and recipient name.

## Consequences
Every organization mail must go through `QueueMail` (or `SendDocuments`). Mail payloads in the log are
encrypted with `APP_KEY`; after a key rotation old messages stay retryable only while the old key is listed
in `APP_PREVIOUS_KEYS`. Workflow email steps (M4) reuse `SendDocuments` with their own templates.
