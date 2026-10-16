# 0025: Small in-house status machine instead of spatie/laravel-model-states
- Status: accepted
- Date: 2026-10-16

## Context
§6.5 asks for a state machine "for example spatie/laravel-model-states" so invalid transitions are
impossible and every transition writes a `submission_events` row and an audit entry.

## Decision
`SubmissionStatus` (backed enum) declares the allowed targets per status; `TransitionSubmission` is the
only code that changes a status, inside the caller's transaction, writing the event row and one audit
entry. A database CHECK constraint limits the column to the seven statuses. No package is installed.

## Consequences
Seven states and nine edges fit in ~40 lines with no extra dependency or class-per-state boilerplate.
If transitions grow side effects per state, revisit spatie/laravel-model-states.
