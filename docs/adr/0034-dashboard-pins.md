# 0034: Saved reports pinned to the dashboard
- Status: accepted (extends 0033)
- Date: 2026-10-16

## Context
§8.1 v1.0: "saved reports (§8.4) can be pinned as widgets"; §8.4: saved reports "can be pinned to the
dashboard". Dashboards are built from DashboardWidgetSources (M5-T04) and cached per organization, viewer
segment, widget and date range (DashboardCache). A saved report is computed for each viewer
(SavedReportQuery), may be private or shared, and can be edited, unshared or deleted at any time.
`cache.serializable_classes` is false, so cached values must be plain arrays.

## Decision
- **Pins are personal:** `dashboard_pins` (organization, user, saved report, position; unique per user and
  report; FKs cascade, so deleting the report, user or organization removes the pin). Tenant-owned model
  `DashboardPin` with its own policy (own pins only). `DashboardPins::pin()` requires
  `SavedReportPolicy::view` and is idempotent; unpinning one's own pin is always allowed.
- **Actions:** "An Dashboard anheften / Pin to dashboard" and "Lösen / Unpin" on the saved reports table
  and the report page. The table selects a per-row `pinned_by_viewer` subquery so action visibility costs
  no query per row.
- **Source and widget:** `PinnedReportsSource` (after `RoleWidgetSource` in `dashboards.widget_sources`)
  returns one `PinnedReportWidget::make(['savedReportId' => …])` per pin the viewer may still view, in pin
  order. The widget (a ChartWidget) draws a line for day/week/month groups, bars for up to
  `dashboards.pinned_reports.max_chart_groups` (12) groups, and otherwise — many groups, no grouping, no
  rows — a compact table of the first `table_rows` (10) rows, "n more groups", the total and a link to the
  report. Numbers are `SavedReportQuery::run(report, dashboard range, viewer)`: the dashboard range is
  intersected with the report's own date filter, and the viewer's submission visibility applies.
- **Cache:** DashboardCache with `CacheScope::User`; the widget key holds the report id, its `updated_at`
  and the locale, so edits show at once. `ReportResult::toArray()/fromArray()` round-trip the result.
- **Access loss:** every read re-checks the pin and `SavedReportPolicy::view` *before* the cache, and
  deletes pins whose report is gone or no longer viewable (unshared, lost `reports.view`). The widget class
  may be mounted by every `reports.view` holder (Filament re-runs `canView()` on each Livewire request):
  an instance whose report went away re-renders empty instead of failing its poll with 403; the next page
  load drops it. The `savedReportId` property is `#[Locked]`; an instance for a report the viewer did not
  pin shows nothing.

## Consequences
Pins are not ordered or hidden by the user yet (§8.1 "Later"); `position` is ready for it. A user who
loses `reports.view` entirely while the dashboard is open gets a 403 on the widget's next poll (the page
reload is clean). Pins of a report the viewer temporarily cannot see are removed, not suspended.
