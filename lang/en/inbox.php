<?php

declare(strict_types=1);

return [
    'label' => 'Submission',
    'plural' => 'Submissions',
    'navigation' => 'Submissions',
    'column' => [
        'number' => 'Number',
        'form' => 'Form',
        'version' => 'Version',
        'status' => 'Status',
        'submitter' => 'Submitted by',
        'created_at' => 'Started',
        'submitted_at' => 'Submitted',
        'updated_at' => 'Last change',
        'revision' => 'Revision',
    ],
    'filter' => [
        'from' => 'Submitted from',
        'until' => 'Submitted until',
        'from_indicator' => 'Submitted from :date',
        'until_indicator' => 'Submitted until :date',
    ],
    'empty' => 'No submissions yet',
    'detail' => [
        'overview' => 'Overview',
        'data' => 'Submitted data',
        'no_data' => 'This submission contains no data yet.',
        'documents' => 'Documents',
        'no_documents' => 'No documents have been generated for this submission yet.',
        'events' => 'History',
        'no_events' => 'No status changes yet.',
        'system' => 'System',
        'missing_file' => 'Not uploaded yet',
        'open_file' => 'Open :name',
        'rows' => ':count rows',
        'row' => 'Row',
    ],
    'value' => ['yes' => 'Yes', 'no' => 'No'],
];
