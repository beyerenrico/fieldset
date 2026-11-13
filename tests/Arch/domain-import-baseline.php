<?php

declare(strict_types=1);

/*
 * What each domain module imports today, frozen by DomainImportBaselineTest (plan KTD13).
 * `modules`: other modules, sorted. `App\Http` / `App\Filament`: the files that import that layer.
 * Remove entries as imports go away; the test fails on a stale entry as it does on a new import.
 */

return [
    'Audit' => [
        'modules' => ['Forms', 'Identity', 'Mail', 'Platform', 'Privacy', 'Reports', 'Submissions', 'Workflows'],
        'App\\Filament' => [
            'Audit/AuditServiceProvider.php',
        ],
    ],
    'Dashboards' => [
        'modules' => ['Forms', 'Identity', 'Mail', 'Reports', 'Submissions', 'Workflows'],
        'App\\Filament' => [
            'Dashboards/Widgets/PinnedReportsSource.php',
            'Dashboards/Widgets/RoleWidgetSource.php',
        ],
    ],
    'Documents' => [
        'modules' => ['Audit', 'Forms', 'Identity', 'Mail', 'Submissions'],
    ],
    'Forms' => [
        'modules' => ['Audit', 'Identity'],
        'App\\Filament' => [
            'Forms/Jobs/ImportFillablePdf.php',
        ],
    ],
    'Identity' => [
        'modules' => ['Audit', 'Notifications', 'Platform'],
    ],
    'Instance' => [
        'modules' => ['Audit', 'Platform'],
    ],
    'Mail' => [
        'modules' => ['Audit', 'Documents', 'Forms', 'Identity', 'Notifications', 'Reports', 'Submissions'],
    ],
    'Notifications' => [
        'modules' => ['Audit', 'Forms', 'Identity', 'Mail', 'Submissions'],
    ],
    'Platform' => [
        'modules' => ['Audit', 'Documents', 'Forms', 'Identity', 'Instance', 'Mail', 'Privacy', 'Submissions', 'Workflows'],
        'App\\Http' => [
            'Platform/Http/EnforceImpersonationWindow.php',
            'Platform/Impersonation/SessionLogin.php',
        ],
    ],
    'Privacy' => [
        'modules' => ['Audit', 'Documents', 'Forms', 'Identity', 'Notifications', 'Platform', 'Submissions'],
        'App\\Filament' => [
            'Privacy/Http/DataExportDownloadController.php',
        ],
        'App\\Http' => [
            'Privacy/PrivacyServiceProvider.php',
        ],
    ],
    'Reports' => [
        'modules' => ['Audit', 'Documents', 'Forms', 'Identity', 'Mail', 'Notifications', 'Submissions'],
        'App\\Filament' => [
            'Reports/Http/ExportDownloadController.php',
        ],
    ],
    'Submissions' => [
        'modules' => ['Audit', 'Forms', 'Identity', 'Mail', 'Workflows'],
    ],
    'Workflows' => [
        'modules' => ['Audit', 'Documents', 'Forms', 'Identity', 'Instance', 'Mail', 'Notifications', 'Privacy', 'Submissions'],
    ],
];
