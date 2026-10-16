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
  `workflows:tick` every minute); `manual` a fresh id per click (amended: per user and 10 s window).
  Listeners run synchronously after commit and report, never throw (amended: status changes queue a job).
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
  always sees the submission (amends 0030; narrowed by the amendment below: open tasks only, and never
  without `view_assigned`). Overdue reminders go out once per task (`reminded_at`).
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

## Amendment 2026-10-16: M4 review hardening
- **Visibility through tasks (S1).** Only `submissions.view_assigned` holders gain visibility through review
  tasks, and only for OPEN tasks assigned to them or one of their groups, plus tasks they decided themselves
  (`completed_by`, so the decider can still open what they approved; the Field App refetches after a decision).
  Assignment alone grants nothing: a Designer who assigns a review to themselves cannot read or complete it.
  Group members who did not decide lose access when the task closes; cancelled tasks grant nothing.
  `POST /tasks/{id}/complete` finds tasks by assignment (user, or group with `submissions.review`), answers
  409 for closed ones and 404 for open ones the user cannot act on.
- **Cancelled tasks and runs (C1, C6).** `set_status` may only target `completed` or `void` (editor, SaveWorkflow,
  and the step for older definitions): review states change only through `request_review` and the decision.
  Whenever a submission leaves `in_review` (any transition to another status), its remaining open tasks become
  `cancelled` and the runs waiting for them end as `cancelled` (new task/run/step status). Completing a
  cancelled task, or a task whose submission already left review, answers 409 `task_closed` (the task is
  cancelled if the listener was missed). A decision while the task's run failed or is being retried answers
  409 `review_blocked` and leaves the task open: an administrator retries the run (WorkflowFailed was sent),
  then the reviewer decides. The admin actions show the message.
- **Recipients who can act (C2, U4).** Review assigned and overdue notifications go only to assignees who can
  complete the task (TaskAccess::canComplete: `submissions.review` for group members, a view permission for
  everyone). Users with admin access get the admin inbox link, others the Field App path.
- **Queued start (C3, S6).** A status change queues `StartWorkflowsForEvent` (after commit, tries and backoff
  like steps): conditions are evaluated there, not in the submit request. The job marks the submission event
  `workflows_processed_at` (processing metadata; the history columns stay append-only). `workflows:tick`
  re-queues events still unprocessed after 10 minutes (last 7 days), so runs and `submitted → completed` are
  not lost; `submitted → completed` only applies to the submission's latest event. JsonLogic gets no operation
  budget (spec package unchanged): the 512-level depth limit stays, evaluation runs on the queue.
- **One review per submit (C4).** SaveWorkflow refuses a second active workflow with `request_review` whose
  trigger fires on submit (`submission.submitted`, or `status_changed` to `submitted`/any) for overlapping forms
  (no form filter overlaps everything). Manual workflows are exempt (one click, one workflow).
- **approved → completed (C4, C5).** Workflows triggered by the approval (`submission.approved`, `status_changed`
  to `approved`/any) are post-approval steps: the submission completes once the approval event has been
  processed, every run it started has completed and the run that resumed after the approval has completed.
  A failed one keeps the submission `approved` (admins are notified); a successful manual retry completes it.
- **Manual runs (S4, C7).** Accepted as designed: holders of `workflows.manage` or `submissions.review` who can
  see the submission may run any active manual workflow of its form. Authors decide what a manual workflow
  does; runs are audited (`workflow.manual_run`); authors can restrict a workflow to users with a condition on
  `trigger.actor_id`. A repeated click by the same user within 10 s returns the first run (event id
  `manual:{user}:{10 s window}`, a click within 10 s of the last run reuses its id).
- **Duplicates on re-claim (S5).** `notify` stores `notified_at` in the step output right after queuing, so a
  re-claimed step does not notify again. `webhook` stores an `in_flight` marker before the POST and removes it
  when the attempt is known to have failed (timeouts and 5xx are retried: at-least-once stays); a re-claimed
  step that still finds the marker (worker died mid-request, outcome unknown) does not POST but fails with a
  clear message, and a manual retry clears it and sends. Overlapping step jobs are dropped
  (`WithoutOverlapping::dontRelease()`), so releases never use up tries.
- **Email caps and definition limits (S3).** Workflow mails are linked to their step run
  (`mail_messages.workflow_step_run_id`) and capped at `workflows.email.per_submission_per_day` (25) and
  `per_organization_per_hour` (500); over a cap the step fails permanently and a later retry sends only the
  missing recipients. SaveWorkflow limits steps (25), steps JSON (64 KB), entries per recipient list (50) and
  address fields per step (10); `notify` needs channels and recipients, `send_email` a recipient source.
- **Webhooks (S7).** URLs are shown and audited masked (`WebhookUrl`: query values and path segments other than
  short lowercase words become "•••"); only ports 80, 443, 8080, 8443 unless `allow_private_hosts`; at most
  64 KB of a response body is kept. Endpoints referenced by a workflow step cannot be deleted.
- **Inbox (S2).** API inbox and admin bell show notifications of organizations with an active membership (and
  those without an organization); a deactivated membership hides them, reactivation shows them again.
- **Idempotency keys (S8).** Verified tenant-scoped (BelongsToOrganization, unique on organization/user/key,
  advisory lock includes the tenant); no change.
