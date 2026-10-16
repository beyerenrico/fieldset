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

## Amendment 2026-10-16: M5 integration
- Filament's own download route (`/filament/exports/{export}/download`) checks only ownership, not the
  tenant. `Filament\Actions\Exports\Models\Export` now has the deny-all `InternalRecordPolicy`, so that
  route answers 403 for every export, also for the requester; ReportExport keeps `ReportExportPolicy`
  and the tenant-scoped `admin/{org}/exports/{id}` route.
- Explorer search: GIN trigram index `submission_values_value_text_trgm_idx` (pg_trgm, a trusted extension;
  the migration skips the index with a warning where the extension cannot be installed). The planner picks
  it on its own for a rare term at 2,500 submissions (SearchPlanTest). It also serves `value_text =`.
- The Field App labels `export_ready` like `lang/*/notifications.php`.
- Saved reports can be pinned to the dashboard (ADR 0034).

## Amendment 2026-10-17: M5 review fixes
- **Saved report form = stored filters (H1, H2):** every stored filter has a field — period or "custom range"
  (from/until date pickers), statuses, submitters, groups, search, typed field filters (choices of all versions,
  yes/no, date pickers, min/max with `.` or `,`). Empty or malformed values are form errors; SaveReport also
  refuses a field filter that does not survive normalization instead of dropping it. "Save as report" in the
  explorer goes through SaveReport, so explorer → save → edit → save keeps every filter.
- **Day boundaries (L4):** `ReportFilters.timezone` stores the time zone calendar days were chosen in (the
  explorer viewer's, or the editor's display time zone in the form); days are evaluated in it everywhere.
  Relative periods stay in the organization's time zone.
- **Scheduled mails (H3, L2):** the mail period REPLACES a relative period of the report (this week, last 7
  days… evaluated at send time would miss the past period); an absolute custom range still intersects. Shown
  as help text on the schedule field. Switching a schedule on or changing it sets `last_sent_at = now`, so the
  first mail covers the next complete period. Scheduled reports are eager-loaded with their form (several
  scheduled reports of one organization failed on lazy loading before).
- **Money per version (M1):** the explorer formats money with the currency of the submission's version
  (`ReportField.versions`); exports add a "<label> (currency)" column after every money key. Aggregates find
  the versions behind each group's values (`string_agg(form_version_id)` only when the field's currency
  differs between versions): one currency → sum/avg in it; several → no sum/avg for that group (`mixed`, shown
  as "—"), and a note names the currencies (table, pinned widget, CSV, mail). Display pads to the scale of the
  currency's versions (JPY 0, scale 3 → 3 digits).
- **Options across versions (M3):** choice labels, explorer filters and groupings use the union of the options
  of all versions; the newest label wins, removed options keep theirs.
- **Explorer order and plans (M2):** default order `submitted_at desc, uuid desc` (non-drafts always have
  submitted_at; drafts are never rows), served by `submissions (organization_id, form_id, submitted_at, uuid)`,
  which replaces `(…, submitted_at)`. QueryCatalog/QueryPlanTest now plan the real explorer page (manager and
  reviewer, filtered) and SavedReportQuery::queries() (grouped and total), not simplified copies.
- **Export rows (M4, L5):** the job takes a snapshot of the selected uuids in the explorer's default order (at
  most `max_rows`) and reads chunks by uuid through the same scope — no OFFSET paging, no skipped or repeated
  rows when data changes, files in explorer order. The PDF ZIP uses the same snapshot.
- **Export limits (M5):** "export all" up to `reports.exports.max_rows` (50,000; refused with a message to narrow
  the filters), at most `max_pending` (2) pending/running exports per user, `per_minute` (10) requests per user
  (RateLimiter). `ExportRefused` messages show as a warning. Exports run on the `exports` queue
  (`REPORTS_EXPORT_QUEUE`), worked by Horizon's `supervisor-exports` (one process, so exports run one after
  another and never hold up `default`); the compose `queue` service runs Horizon and picks it up.
- **Bounded groups (M6):** at most `reports.saved.max_groups` (500) groups (`LIMIT N+1`, `truncated` flag and a
  note in table, widget, CSV and mail; time groups keep the newest). The total row covers all submissions.
  Decision: text, email and hidden stay groupable — the cap bounds them, and grouping by a free-text city or a
  hidden code is a real use.
- **Labels and CSV (U1, U8):** day/week/month groups are labelled in the locale ("06.10.2026", "KW 41 2026",
  "Oktober 2026") while keys stay sortable ISO strings. CSV follows the locale (CsvDialect: de `;` and decimal
  comma, en `,`); digits are never changed. Report CSV headers are neutralized like cells.
- **Notifications (U5):** a failed export is a `danger` notification without a link; a finished one has a
  "Download" button (`NotificationMessage.status` / `actionKey`; mail button too). An expired link answers 410
  with a page that explains the retention and links to the explorer. Notification lines with a `count`
  parameter use plural forms.
- **Filters (L1):** "No" on a yes/no field matches an answered `false` only, not submissions whose version has
  no such field.
- **Index builds (L3):** M5 index migrations run outside a transaction with `CREATE INDEX CONCURRENTLY`
  (also the trigram index); RefreshDatabase runs migrations outside its test transaction, so tests use them as is.
- **Key collisions:** keys truncated to 64 characters that two elements of one version share are listed by
  `FieldCatalog::collisions()` and logged (their values mix in submission_values); not resolved automatically.
