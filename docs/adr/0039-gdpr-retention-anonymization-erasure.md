# 0039: GDPR retention, legal holds, anonymization, data export and erasure
- Status: accepted
- Date: 2026-10-17

## Context
§11 Privacy asks for per-form retention that deletes or anonymizes submissions after N months, a legal hold
("invoices must be kept for years under German law"), user data export and erasure. Submissions are
row-level tenant data with a `submission_values` read model, attachments on the private disk and immutable
PDF renditions (`input_hash`, `file_sha256`; a trigger refuses UPDATE and allows DELETE only with
`app.allow_immutable_delete`). Users are platform accounts that can belong to several organizations.

## Decision
- **Retention per form** (`retention_policies`: form, `months` or null = keep indefinitely, action
  `delete|anonymize`). Set on the Retention page by Owners and Admins (`organization.manage`) through
  `SaveRetentionPolicy` (1–1200 months; one `privacy.retention_changed` entry per effective change). A
  submission is due when its submit time — for drafts the creation time — is older than the period.
- **Legal holds** (`legal_holds`, on a form or on one submission, with reason, placed/released by; released
  holds stay as history). While a hold is active the retention job leaves the held submissions alone. A form
  under hold needs a retention period of at least `privacy.retention.legal_hold_min_months` (default 120 =
  ten years; the operator checks the statutory periods that apply, e.g. §147 AO / §14b UStG for invoices —
  not verified here): saving a shorter period is refused, and so is placing a hold on a form with a shorter
  one, so lifting a hold never releases records into a too-short retention. Placing and releasing are
  audited (`privacy.legal_hold_placed|released`).
- **Retention job.** `privacy:apply-retention` (daily 02:30) queues `ApplyOrganizationRetention` per
  organization with a policy; the job runs inside that organization's TenantContext, processes at most
  `privacy.retention.batch` (500) submissions per form and run and re-queues itself while more are due.
  Idempotent: anonymized submissions are marked (`submissions.anonymized_at`), deleted ones are gone; one
  broken submission is reported and skipped for a day (it never starves the rest of the form). Each
  submission is processed under its row lock and re-checks the holds there, with a share lock on its form
  row; placing a hold and changing the retention lock that row for update, so a hold placed while a batch
  runs always wins. Entries: one per submission (`submission.anonymized` /
  `submission.deleted`, system actor, with number and rendition hashes) and one summary per form
  (`privacy.retention_applied`).
- **Anonymization** keeps what reports aggregate and removes what identifies people: number, money, rating,
  date, time, datetime, select, multiselect, radio, checkbox, toggle and non-text calculated values stay;
  text, textarea, email, phone, photo, file, signature, location, hidden, text calculations and unknown keys
  go; kept types with a personal role (`customer.*`, `site.*`, `payment.*`) go too. Repeater rows stay with
  their kept values (row counts stay right). Device, location and every user reference (`created_by`,
  `updated_by`, `submitted_by`, event actors and comments) are cleared, the revision is bumped, and
  `submission_values` are re-projected from the anonymized data (a `changes_requested` submission keeps its
  last submitted values, minus the removed keys). Traces elsewhere go too (`SubmissionTraces`): the mail log
  rows of the submission (recipient, subject, stored mailable), customer send recipients, document links,
  review task comments and assignees, the trigger actor of workflow runs, step logs and the outputs of
  finished steps (which hold recipient addresses).
- **Attachments and renditions.** Anonymization deletes attachments (rows and files) and the PDF files,
  because both contain the personal data; the rendition **rows stay** (immutable: form and design version,
  `input_hash`, `file_sha256`, page count) as proof of what was issued, and the hashes are also copied into
  the audit entry. Keeping the rows also stops the rendition sweep from re-rendering the submission. PDFs are
  kept only under legal hold (held submissions are not processed). Deletion removes the submission with
  everything that cascades (values, events, attachments, renditions with the session flag switched on and
  off inside the transaction, sends, links, tasks, runs), its mail log rows (the foreign key would only null
  them) and the files after commit. Drafts that are due are
  always deleted, also under an anonymize policy (never submitted, no legal value).
- **User data export** (`data_exports`, shared with the queued audit CSV of ADR 0038): self-service on "My
  data" for every member, and for Owners/Admins (`members.manage`) on "Personal data". `RequestDataExport`
  stores the request, writes `privacy.data_export_requested` and queues `RunDataExport` after commit; the job
  runs in the tenant, re-checks the policy and writes a ZIP: `profile.json`, `memberships.json`
  (self-service: all of the account's memberships; by an admin: this organization's), `devices.json`,
  `submissions.json` (created or submitted by the user in this organization; the content is the
  organization's record, so it is included only where the requester may still see the submission —
  otherwise `data_withheld`; attachment metadata, no files) and `audit-log.json` (entries about or by the
  user in this organization; IP address, user agent, diff and details only for the user's own actions, not
  for what others did to them), plus a README. Never
  included: password hash, MFA secret, recovery codes, tokens. The requester gets a notification with a
  **signed** link (`admin/{org}/data-exports/{id}`, valid until the file expires, plus the requester's
  session and DataExportPolicy); downloads are audited (`data_export.downloaded`), expired files answer 410;
  `model:prune` removes rows and files after `privacy.exports.keep_days` (7). One open export per requester
  and kind, `privacy.exports.per_hour` (10) requests.
- **Erasure** (`EraseUser`). The account row stays so that records the organization must keep stay
  attributed — to "Deleted user": name, email (`erased-{id}-{random}@erased.invalid`), password, MFA, locale and time
  zone are replaced, `users.erased_at` set and the account deactivated; sessions, API tokens, external (SSO)
  identities, database notifications, notification preferences and the user's data exports are deleted;
  device names are cleared and every device asked to wipe; memberships are deactivated and roles and groups
  removed; pending password resets and mail log rows sent to the old address are deleted. The attribute
  changes are not written to the audit log (they are the data being erased); one `privacy.user_erased`
  entry without personal data is. Refused while the user is the last active Owner of an
  organization. Self-service ("Delete my account", password confirmation) erases the whole account. An admin
  may erase only accounts with no membership in another organization, active or not (otherwise the account
  is shared and the person deletes it themselves) and an Owner only as an Owner.
- **What stays.** Submissions created by an erased user (their content is the organization's record; it
  follows the form's retention), and audit entries: the log is append-only and tamper-evident (ADR 0038), so
  entries written before the erasure or anonymization keep their values (e.g. the email of
  `member.invited`, a reviewer's comment in `submission.rejected`) until audit retention removes them — a
  legitimate-interest / legal-obligation retention the operator documents.

## Consequences
Field App devices may still hold cached copies of anonymized or deleted submissions until their next sync or
wipe. A download link of an anonymized submission's PDF answers 410 `document_anonymized` (the file is gone;
the rendition row stays; see the amendment). The Field App has no self-service export or erasure screen yet; field workers ask an Admin, who uses
"Personal data". The legal-hold minimum and the statutory periods are configuration, not legal advice.

## Amendment (2026-10-17, M8 review)
- **Decision under the row lock.** The batch read is only a candidate list. `RetainSubmission` locks the
  submission row, takes the share lock on the form row (the one `SaveRetentionPolicy` and `PlaceLegalHold` take
  for update) and then re-reads the holds, the form's current policy and the submission's status and due-ness
  (`COALESCE(submitted_at, created_at)` older than the period) before it decides delete, anonymize or skip. A
  draft submitted meanwhile, a submission resubmitted meanwhile, a period raised or set to "keep" during the run
  is left alone; an action switched to delete is followed.
- **Time box.** `ApplyOrganizationRetention` starts no new submission after 60 s (at least one per run) and
  re-queues itself; its timeout (85 s) stays below the queue's `retry_after` (90 s), so a slow run is never
  picked up twice. The run stays idempotent.
- **Notifications.** Anonymization and deletion also delete the in-app notifications about the submission
  (`notifications.data->>'submission_uuid'`): their rendered title and body can quote a review comment or a
  customer name. Large instances may want an expression index on that key (not added here).
- **Erasure.** The replaced address gets a random suffix (`erased-{id}-{random}@erased.invalid`), so an existing
  account with the predictable address never blocks an erasure. An admin may not erase a platform operator's
  account (the StartImpersonation rule: a `platform_admins` row); the operator access is removed first.
- **No dead ends.** "Personal data" disables Erase, with the next step as tooltip, for accounts shared with
  another organization and for platform operators. "Delete my account" asks SSO-only accounts (linked to an
  external identity, no `auth.password_changed` entry, i.e. the random password of SSO provisioning) to type
  their email address instead of a password they never had. Field workers have no admin access and still ask
  an Admin, or the platform operator for a shared account.
- **UI.** Errors of modals with inputs (domain validation included) stay at the field with the modal open
  (§9.1); shortening a period (also from "keep") asks for a second confirmation with the number of submissions
  the next nightly run removes; "Place legal hold" explains itself and is disabled while the period is below the
  minimum. Erased accounts are stored with the neutral marker "Deleted user" and shown translated
  (`privacy.erased_name`, `AccountScrubber::displayName`).
