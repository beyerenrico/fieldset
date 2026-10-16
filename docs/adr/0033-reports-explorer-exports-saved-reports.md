# 0033: Submission explorer, exports and saved reports on the submission_values read model
- Status: accepted
- Date: 2026-10-16

## Context
M5 (BUILD_PROMPT §8.4, §8.6, §8.11) needs a per-form explorer whose columns come from the schema keys
of all versions, queued exports (XLSX with repeaters, CSV, PDF ZIP) that reuse Filament's `Export`
model and completion notification, saved reports with count/sum/avg as table and chart, scheduled
report emails, and "Reports and exports follow the View submissions row". Filament's Exporter has
static columns and one sheet, openspout numeric cells only take `int|float`, and a Reviewer may only
export what they may see.

## Decision
- **Columns (FieldCatalog):** the union of the data keys of all published versions, keyed exactly like
  the projector builds `submission_values.key` (layout elements add nothing, repeater children are
  `repeater.child`). Label, type and options come from the newest version that has the key; order is
  the newest version's document order, then keys only older versions have. Repeaters show a row count
  (rows with at least one value) in the explorer and get their own sheet/file in exports.
- **One row selector (ReportScope):** SubmissionVisibility + form + never drafts + filters. Field filters
  (`in` for select/radio/multiselect, `is` for checkbox/toggle, `between` for dates and decimals) and the
  search are `uuid IN (SELECT submission_uuid FROM submission_values WHERE organization_id, form_id,
  key, value…)` semi-joins on the read-model indexes; no jsonb parsing of `submissions.data`. No status
  filter means every status except draft and **void**. The explorer, exports, saved reports and
  scheduled emails all go through it, so exported rows are always the rows the user may view **when
  the job runs** (re-checked in the job, together with `reports.view`).
- **Explorer table:** Filament page `?form=<id>` (`reports.view`; forms with visible submissions only).
  Two correlated subqueries per row pivot the values (`jsonb_object_agg(key, value_text)` of top-level
  rows, repeater row counts); sorting by a field orders by its typed column (value_num / value_date /
  value_text). The table's filter state converts to `ReportFilters` (FormExplorerFilters), which the
  export and "save as report" store.
- **Exports:** `ReportExport` extends Filament's `Export` model (table `exports`, our migration with
  organization, form, format, status, options, file path/size, expiry). `StartExport` authorizes, counts,
  writes one `report.exported` audit entry and queues `RunExport` after commit; the job claims
  pending→running, streams chunks of 500 submissions (keyset on uuid, one `submission_values` query per
  chunk) through openspout to a temp dir, stores the file on the private disk
  (`exports/{org}/{id}/…`), and notifies via the Notifier (`export_ready`, database + mail by default).
  The in-app notification is Filament's database notification with the download link as its action,
  i.e. Filament's completion pattern; Filament's own exporter, `ExportCompletion` job and download
  route are not used (static columns; the route has no tenant). Download: `admin/{org}/exports/{id}`
  (session, member, `ReportExportPolicy::download` = requester with `reports.view`); 410 after
  `reports.exports.keep_days` (7), when `model:prune` deletes row and file. Job timeout 85 s (below the
  queue's 90 s retry_after); exports beyond that need a dedicated long-running queue (open issue).
- **Formats:** XLSX sheet 1 = number, status, submitter, submitted at, version, one column per top-level
  key; one sheet per repeater (nested: "Parent › Child") with number + 1-based row ("1.2" when nested).
  CSV = `submissions.csv`, or a ZIP with one more CSV per repeater when the form has repeaters (one CSV
  cannot hold both shapes). Numbers and money are written as the exact decimal **strings** from the
  submission (openspout numeric cells need floats); integers (row counts, versions) are numeric, dates
  are date cells, booleans boolean cells, choices their labels. Text is always a string cell in XLSX
  (no `Cell::fromValue`, which turns "=…" into a formula); CSV text starting with = + - @ tab CR (not a
  number) gets a leading apostrophe. PDF ZIP: latest rendition per submission, named by number,
  capped by `reports.pdf_zip.max_bytes`/`max_files`; left-out submissions are counted in the notification.
- **Saved reports:** form, filters (jsonb, same shape as `ReportFilters`, plus a relative `period`),
  group-by (`status|submitter|version|day|week|month|field:<key>`; day/week/month in the organization's
  time zone, date fields by month), metric count/sum/avg over a numeric key (repeater children are
  summed over all rows), visibility private|organization. Aggregates run in SQL on numeric(38,10):
  `trim_scale(sum(...))::text`, `trim_scale(round(avg(...), 10))::text` — decimal strings, never PHP
  floats. `SavedReportQuery::run(report, ?DateRange, ?viewer)` authorizes `view` and always computes for
  the viewer, so a shared report shows each person their own numbers; the dashboard pins use it.
- **Scheduled emails:** `reports:send-scheduled` hourly; weekly = Mondays, monthly = the 1st, at
  `reports.schedule.hour` (07:00) in the organization's time zone, covering the previous week/month.
  Due once per period (`last_sent_at`, or `created_at` for a new report), missed runs catch up.
  Recipients are members with `reports.view` (private reports: only the creator); each gets the result
  computed as themselves, in their locale, as a CSV attachment of a `BrandedMail` sent via QueueMail.
- **Permissions:** the existing `reports.view` capability (§8.6 column; Owner, Admin, Designer,
  Reviewer, Viewer) gates the explorer, exports and saved reports; saved reports are edited by their
  creator, shared ones also by `organization.manage` holders.

## Consequences
Export numbers are text cells in Excel (green triangle; "Convert to number" when summing) — exactness
over convenience. Repeater rows whose values are all empty are not counted (they are not projected).
The explorer search scans the form's values (`ILIKE`, no trigram index). Very large exports are bounded
by the job timeout until a long-running export queue exists. The Field App shows `export_ready` with its
generic label fallback until it gets a translation.
