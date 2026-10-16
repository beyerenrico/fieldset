# 0030: Submission visibility in the admin inbox
- Status: accepted
- Date: 2026-10-16

## Context
The §8.6 matrix row "View all submissions" grants Owner/Admin/Viewer all, Designer "own forms",
Reviewer "assigned/group" and Field Worker "own (+group if granted)". Review assignments arrive with the
M4 tasks, and "own forms" is not defined further. The admin inbox (M3-T09) must not show more than the API.

## Decision
- One rule set, `SubmissionVisibility`, serves the API (`GET /submissions`, detail, documents, send) and the
  admin inbox (`SubmissionResource::getEloquentQuery`, `SubmissionPolicy::view`).
- `submissions.view_all` (Owner, Admin, Viewer): every submission of the organization.
- Designer (`view_own_forms` + `view_own`): submissions of the forms they created (`forms.created_by`) plus
  their own submissions.
- Reviewer (`view_assigned` + `view_own`): until M4 assignments exist, submissions created by members of
  the groups they share with `share_submissions = true`, plus their own. M4 adds assigned tasks to the rule.
- Field Worker (`view_own`): own submissions.
- "Group" applies to every role with `view_own` or `view_assigned`: members of a group with
  `share_submissions = true` also see the submissions of the other members of that group.
- Actions inside a visible submission have their own capability checks (re-render: `forms.manage` or
  `submissions.review`; send to customer: creator, `submissions.review` or `organization.manage`).

## Consequences
A designer does not see submissions of forms a colleague created, even if they edit them later; transfer
of form ownership is not modelled. When M4 introduces assignments, Reviewer visibility widens to assigned
submissions in `SubmissionVisibility` only, and both surfaces follow automatically.
