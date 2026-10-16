# 0032: Workflow engine, review tasks and lifecycle rules
- Status: accepted (amends 0028 and 0030)
- Date: 2026-10-16

## Context
§8.3 asks for workflows (trigger + JsonLogic conditions + ordered steps) that run as queued jobs, are
idempotent per (workflow, submission, triggering event), retry with backoff, show failures, notify admins
and can be retried by hand; review tasks; webhooks with an SSRF guard. §6.5 has two edges no user action
covers: `submitted → completed` ("no review configured") and `approved → completed` ("post-approval steps
done"). Submit runs inside the idempotency transaction (ADR 0027).

## Decision
- **Own engine, no package.** `workflows` (trigger type + `trigger_config` {to_status | cron | form_ids},
  `conditions` JsonLogic, `steps` in the Filament Builder shape `[{type, data}]`), `workflow_runs`
  (`definition_snapshot`, trigger context, status pending|running|waiting|completed|failed|rejected,
  `current_step`, error) and `workflow_step_runs` (status, attempts, log, output). Unique
  `(workflow_id, submission_uuid, event_id) NULLS NOT DISTINCT`; `createOrFirst` turns a duplicate event
  into "find the existing run", so no step runs twice (AC "duplicate events do not send duplicate emails").
- **Events and ids.** `TransitionSubmission` fires `SubmissionStatusChanged` after commit with the
  `submission_events` row id; the submission triggers (submitted, status_changed{to}, approved, rejected =
  to `changes_requested`) all derive from it with event id `submission_event:{id}` — a resubmit is a new
  transition, so a new run. `PublishForm` fires `FormPublished` (`form_version:{id}`, no submission).
  `schedule` uses the minute (`schedule:{UTC minute}`, cron in the organization's time zone,
  `workflows:tick` every minute); `manual` a fresh id per click. Listeners run synchronously after commit
  and report, never throw.
- **Conditions** use the form-schema-php evaluator (ADR 0021) over
  `{submission: {uuid, data, status, number, form_key, version, submitted_at, submitted_by}, form, trigger}`;
  empty = always, evaluation error = never. The editor rejects JSON that fails `evaluateStrict` on a sample.
- **Execution.** One `RunWorkflowStep` job per step (ids only, tenant restored, organization locale for log
  lines), dispatched via `DB::afterCommit` with errors reported. `claim()` locks the run and makes duplicate
  or stale jobs no-ops; `WithoutOverlapping` per step. Retryable errors: 5 tries, backoff 10/30/90/270 s;
  `StepFailedException` (bad config, refused address, missing data) fails at once. A failure marks step and
  run failed with a scrubbed error, writes `workflow.run_failed` and notifies Owners/Admins
  (`WorkflowFailed`). Manual retry restarts at the failed step (`workflow.run_retried`). `workflows:tick`
  also re-queues runs without progress for 30 min (lost dispatch). Handlers keep what they did in the step
  output (queued mail ids, render request id, task) so a re-run never repeats it.
- **Steps.** `send_email` (fixed / field value or role / submitter / group; mail module templates; PDF
  attached up to the limit or as a link; the newest rendition since the latest submit, else rendered in the
  step), `notify` (WorkflowNotice, channels), `request_review`, `set_status` (state machine; already there =
  no-op), `generate_document` (`RenderSubmission` gains an explicit design version, reason `workflow`; an
  explicit design skips the pin check, and the first rendition pins whichever design it used — amends 0028),
  `webhook` (below).
- **Review tasks.** `request_review` creates one task per step run (user or group, `due_at` from SLA hours),
  sets `in_review`, notifies, and pauses the run. Approve → `approved` + resume; reject (comment required)
  → `changes_requested` with the comment on the submission event, run `rejected`; a resubmit starts a new
  run. Completing needs `submissions.review` or being the assignee user, plus seeing the submission; the
  API lists/acts on tasks assigned to the user or their groups (group members need `submissions.review`).
  `view_assigned` holders see submissions with tasks assigned to them or their groups, an assignee user
  always sees the submission (amends 0030). Overdue reminders go out once per task (`reminded_at`).
- **Lifecycle rules.** After a submit, if no run started by that event contains a step that manages the
  status (`request_review` or `set_status`), the submission moves `submitted → completed`
  (`workflows.complete_without_review`; off when `APP_ENV=testing`, like `documents.render_on_submit`, so
  unrelated tests keep a `submitted` submission — workflow tests switch it on). When a run that
  manages the status completes and the submission is `approved`, it moves to `completed`. Both through
  `TransitionSubmission` (event row + audit + StatusChanged).
- **Webhooks.** `webhook_endpoints` (secret encrypted, hidden, not audited). Body: event, ids, submission
  summary, data snapshot. `X-Timestamp` + `X-Signature: sha256=HMAC(secret, "{timestamp}.{body}")`. SSRF:
  http(s) only, no URL credentials, every resolved address must pass `PublicAddress` (PHP global-range filter
  plus multicast, NAT64, 6to4 and IPv4-mapped blocks — shared with `SmtpHostGuard`), the request connects to
  the validated address (`CURLOPT_RESOLVE`, Host/SNI keep the name), no redirects, no proxy;
  `workflows.webhooks.allow_private_hosts` for development. Redirects and 4xx (except 408/425/429) fail at
  once; network errors, 408/425/429 and 5xx retry.

## Consequences
One review per run (a second `request_review` after approval fails: `approved` cannot go back to
`in_review`). Approval by an unassigned reviewer is possible in the admin inbox only. With the sync queue
(tests) a failing step fails its run at the first error; retries/backoff apply on real queues. The
scheduler must run (`workflows:tick`). Webhook delivery is at-least-once (a timeout after the receiver
accepted retries); receivers dedupe by `X-Webhook-Id`.
