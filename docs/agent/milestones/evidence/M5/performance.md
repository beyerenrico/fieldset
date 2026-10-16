# M5 performance evidence (M5-T05)

BUILD_PROMPT §8.1 AC: widget queries stay index-backed at 100k submissions per organization.
§8.4 AC: an export of 10k submissions completes in the background. Measured 2026-10-16.

## Setup

- Host: Apple M1 Pro, 32 GB; Docker Desktop VM with 10 CPUs and 15.6 GB. Postgres 16.15 (alpine) from
  `docker compose`, default settings. PHP 8.4 on the host (the app image had not been rebuilt with this
  code, so the commands ran from the host against the dev database `form_schema` on port 54329).
- Data: `php artisan app:seed-load demo 100000` into the demo organization's service report.
  The generator inserts in chunks of 1,000 submissions per transaction, without model events.
  Organization `demo` after the load:

  | Table | Rows |
  |---|---:|
  | submissions | 100,008 (100,000 generated: 5 % draft, 10 % submitted, 10 % in review, 2 % changes requested, 3 % approved, 68 % completed, 2 % void, submitted over the last 365 days) |
  | submission_values | 2,328,768 |
  | tasks | 40,269 |
  | renditions | 71,272 (rows only, no files) |
  | workflow_runs | 38,298 (3 % failed) |
  | mail_messages | 16,834 (sent document mails) |

- Generation took 190 s (36 s of PHP CPU time; the rest is Postgres writing 2.6M rows into indexed tables).
  `app:seed-load demo --purge` removed the generated rows in 31 s. Afterwards the dev database was
  restored with `php artisan migrate:fresh --seed --force`.
- Timings: `php artisan app:dashboard-benchmark demo --runs=5`. "Median ms" is the median of 5
  `EXPLAIN (ANALYZE, BUFFERS)` execution times after one warm-up run. "Full scans" lists sequential scans,
  and index scans without an index condition, on the large tables (submissions, submission_values, tasks,
  renditions, workflow_runs, mail_messages).

## How the tests check index use

`tests/Feature/Performance/QueryPlanTest.php` generates 2,500 + 1,000 submissions in two forms (about 80k
value rows) plus a neighbouring organization, runs `ANALYZE`, then checks every query in
`App\Domain\Dashboards\Performance\QueryCatalog`. The catalog holds each widget query exactly as the widgets
build it, plus the standard `submission_values` lookups.

1. **An index exists and fits.** Each query is EXPLAINed with `SET LOCAL enable_seqscan = off`. With that
   setting the planner avoids a sequential scan whenever any index can serve the predicates. A remaining Seq
   Scan, or an index scan without an index condition, on a large table therefore means that no usable index
   exists. The test asserts that there are none.
2. **The new indexes are the ones chosen.** For the submissions KPI, the chart, stale drafts and the
   explorer page, the test asserts the index name.
3. **The planner picks indexes without help.** At test scale, the selective lookups are asserted
   index-backed under default planner settings.

Without the new migration, assertions 2 and 3 fail: Seq Scan on submissions for the KPI, the chart, recent
submissions and the explorer page. Which plan the planner picks by itself depends on table size, so the
plans at 100k are recorded below.

## Index changes (`2026_10_19_100050_add_dashboard_and_report_indexes`)

| Index | For |
|---|---|
| `submissions (organization_id, submitted_at)` | submissions KPI, chart per day, recent submissions |
| `submissions (organization_id, form_id, submitted_at)` | explorer page of one form, newest first |
| `submissions (organization_id, updated_at) WHERE status = 'draft'` | stale drafts KPI |
| `tasks (completed_by)` | reviewer visibility: the "tasks I decided" branch (also the foreign key's ON DELETE) |
| `submission_values (organization_id, form_id, key, submitted_at)`, replacing `(…, key, status, submitted_at)` | lookups of one key ordered by date, and aggregates since a date (no code filters values by status) |
| `submission_values (submission_uuid, key)`, replacing `(submission_uuid)` | per-submission joins in saved reports and exports; the prefix still serves deletes and the foreign key |

Effect of the two `submission_values` changes at 100k (same data, before → after):

| Query | Before ms | After ms |
|---|---:|---:|
| values: choice equals, newest first | 220.66 | 0.07 |
| report: sum of a number grouped by a choice (10k slice) | 292.40 | 21.42 |
| report: count/sum/avg of a number (10k slice) | 13.97 | 5.92 |
| values: number range, newest first | 2.26 | 0.47 |
| explorer: page filtered by a number range | 6.76 | 2.64 |

## Widget queries at 100k submissions

| Query | Median ms | Rows | Scans | Full scans of large tables |
|---|---:|---:|---|---|
| kpi: submissions in range (last 7 days) | 0.26 | 1 | Index Only Scan using submissions_org_submitted_at_idx | none |
| kpi: pending reviews | 2.23 | 1 | Bitmap Index Scan using tasks_organization_id_status_due_at_index | none |
| kpi: stale drafts | 0.53 | 1 | Index Only Scan using submissions_org_drafts_updated_at_idx | none |
| kpi: documents sent | 0.14 | 1 | Index Scan using mail_messages_organization_id_status_created_at_index | none |
| chart: submissions per form and day | 1.29 | 7 | Index Scan using submissions_org_submitted_at_idx | none |
| table: workflow failures | 0.24 | 10 | Index Scan using workflow_runs_organization_id_status_index | none |
| table: review queue (reviewer) | 4.23 | 6 | Bitmap Index Scans on tasks_assignee_user_id_status_index and tasks_assignee_group_id_status_index; submissions_pkey; **Seq Scan on tasks** (visibility subquery) | Seq Scan on tasks |
| table: recent submissions (sees all) | 0.02 | 10 | Index Scan using submissions_org_submitted_at_idx | none |
| table: recent submissions (reviewer visibility) | 4.21 | 10 | Index Scan using submissions_org_submitted_at_idx; **Seq Scan on tasks** (visibility subquery) | Seq Scan on tasks |
| table: draft forms | 0.02 | 2 | Index Scan using forms_organization_id_status_index | none |
| table: recently published | 0.02 | 9 | Index Scan using form_versions_organization_id_form_id_index | none |

Widget data end to end (uncached `DashboardData` call in PHP, including eager loads and mapping; median of 5):

| Widget | Median ms |
|---|---:|
| KPIs (4 counts) | 8.99 |
| Submissions over time per form | 4.51 |
| Workflow failures | 3.67 |
| Review queue (reviewer) | 13.32 |
| Recent submissions (sees all) | 5.10 |
| Recent submissions (reviewer) | 14.24 |
| Draft forms | 1.76 |
| Recently published | 7.73 |

Results are cached for 60 s per organization, plus per user where visibility differs (`dashboards.cache_ttl`).

**The Seq Scan on tasks is the planner's choice.** It sits in the reviewer-visibility subquery of
`SubmissionVisibility` (ADR 0030/0032): open tasks for me or my groups, or tasks I decided. For the demo
reviewer that predicate matches about 14k of the organization's 40k tasks (35 %). Postgres reads the 40k-row
table in about 4 ms instead of combining three bitmap indexes. With literal values the same subquery runs as
a BitmapOr of `tasks_organization_id_status_due_at_index` and `tasks_completed_by_idx` in 3 ms. The
`enable_seqscan = off` test confirms that this index path exists. The alternative is rewriting the
visibility rule as EXISTS or UNION branches, which is a change to the Submissions domain; it is listed as a
follow-up.

## submission_values lookups (explorer and reports) at 100k

The 10k slice is the 10,000 newest submissions of the form (`submitted_at >= 2026-09-08 20:43:51`).
The lookup keys come from the definition: number `net_total`, date `service_date`, choice `service_type`.

| Query | Median ms | Rows | Scans | Full scans |
|---|---:|---:|---|---|
| values: number range, newest first | 0.47 | 50 | Index Scan using submission_values_org_form_key_submitted_at_idx | none |
| values: date range, newest first | 2.64 | 50 | Index Scan using submission_values_organization_id_form_id_key_value_date_index | none |
| values: choice equals, newest first | 0.07 | 50 | Index Scan using submission_values_org_form_key_submitted_at_idx | none |
| explorer: page of a form, newest first | 1.25 | 25 | Index Scan using submissions_org_submitted_at_idx | none |
| explorer: page filtered by a number range | 2.64 | 25 | submissions_org_submitted_at_idx + submission_values_submission_key_idx | none |
| explorer: page filtered by a choice | 4.48 | 25 | submissions_org_submitted_at_idx + submission_values_submission_key_idx | none |
| **10k aggregate**: count/sum/avg of `net_total` | 5.92 | 1 | Index Scan using submission_values_org_form_key_submitted_at_idx | none |
| **10k aggregate**: sum of `net_total` grouped by `service_type` | 21.42 | 3 | Bitmap Index Scan using submission_values_org_form_key_submitted_at_idx + submission_values_submission_key_idx | none |

### Reports agent code at 100k (work in progress at the time of measurement)

These calls used `ReportScope`, `SavedReportQuery` and `StartExport`/`RunExport` from `app/Domain/Reports`,
run as the owner unless noted. The measurement script lived in the session scratchpad.

| Query | Median ms | Scans | Full scans |
|---|---:|---|---|
| explorer page (25 newest) | 2.29 | submissions_org_submitted_at_idx | none |
| explorer count (all rows) | 11.36 | Index Only Scan using submissions_organization_id_form_id_status_index | none |
| explorer page, choice filter | 6.21 | submissions_org_submitted_at_idx + submission_values_submission_key_idx | none |
| explorer page, number filter | 9.78 | submissions_org_submitted_at_idx + submission_values_submission_key_idx | none |
| explorer page, search "Müller" (`value_text ILIKE '%…%'`) | 392.17 | **Seq Scan on submission_values**, Seq Scan on users | Seq Scan on submission_values |
| explorer page (reviewer visibility) | 8.06 | submissions_org_submitted_at_idx; Seq Scan on tasks (see above) | Seq Scan on tasks |

- **Saved report over the 10k slice** (sum of `net_total` by `service_type`): 208 / 134 / 125 ms end to end
  over three runs. Its two aggregate queries run in 64 ms and 49 ms; both use
  `submissions_org_submitted_at_idx` plus `submission_values_submission_key_idx`, one lookup per submission.
- **Export of 10k submissions** (`StartExport` → `RunExport`, run inline with `QUEUE_CONNECTION=sync`,
  filter `from 2026-09-08`):
  - XLSX: 10,024 rows, completed in 5.8 s, peak memory 68 MB, 2.4 MB file.
  - CSV: 10,024 rows, completed in 2.7 s, peak memory 70 MB, 1.2 MB zip.

  In production the job runs on the queue, so it completes in the background. The files were deleted
  afterwards.

## Open findings

1. **Free-text search in the explorer is not index-backed.** `value_text ILIKE '%term%'` reads every value
   of the form: 392 ms at 2.3M rows. A B-tree cannot serve a leading wildcard. An index on long text
   (textarea values) can also exceed the B-tree row limit (about 2.7 kB). The options are a `pg_trgm` GIN
   index on `value_text`, which needs the extension (trusted since PostgreSQL 13), or prefix-only search.
   This is a decision for the explorer owner.
2. **Reviewer visibility reads all of the organization's tasks.** The planner chooses the scan because the
   predicate is unselective (see above). It costs about 4 ms at 40k tasks. Rewriting
   `SubmissionVisibility` with EXISTS or UNION branches would let each branch use its index.
3. **The load generator assigns review tasks to the organization's first group** (in the demo seed, the
   field crew). Visibility therefore comes mostly from tasks the reviewer decided. This makes the reviewer
   numbers above slightly pessimistic.
