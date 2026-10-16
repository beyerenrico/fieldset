<?php

declare(strict_types=1);

use App\Domain\Dashboards\Widgets\RoleWidgetSource;

/*
 * Admin dashboards (BUILD_PROMPT §8.1): role-aware widget sets, a date-range filter and a short
 * per-organization cache.
 */
return [
    // Seconds a widget result stays cached (keys: organization, user scope, widget, date range).
    'cache_ttl' => (int) env('DASHBOARD_CACHE_TTL', 60),

    // A draft counts as stale when it was not updated for this many days.
    'stale_draft_days' => (int) env('DASHBOARD_STALE_DRAFT_DAYS', 14),

    // Default date range when the filter is empty: the last N days including today.
    'default_range_days' => 7,

    // Longest range the filter accepts (longer ranges are shortened from the start).
    'max_range_days' => 366,

    // Forms with their own line in "Submissions over time"; the rest are summed as "Other forms".
    'chart_max_series' => 5,

    // Rows in the dashboard tables.
    'table_limit' => 10,

    /*
     * Widget sources (DashboardWidgetSource), asked in this order for the widgets of the current user.
     * The role-aware defaults come first; pinned saved reports plug in here as another source.
     */
    'widget_sources' => [
        RoleWidgetSource::class,
    ],
];
