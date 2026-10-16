<?php

declare(strict_types=1);

return [
    'filters' => [
        'start' => 'From',
        'end' => 'Until',
        'hint' => 'Without dates the dashboard shows the last :days days. Showing: :range.',
    ],

    'kpis' => [
        'heading' => 'Key figures',
        'submissions' => 'Submissions',
        'pending_reviews' => 'Pending reviews',
        'pending_reviews_hint' => 'Open review tasks right now',
        'stale_drafts' => 'Stale drafts',
        'stale_drafts_hint' => 'Not updated for :days days',
        'documents_sent' => 'Documents sent',
    ],

    'chart' => [
        'heading' => 'Submissions over time per form',
        'other_forms' => 'Other forms',
        'empty' => 'No submissions in this period.',
    ],

    'review_queue' => [
        'heading' => 'Review queue',
        'empty' => 'Nothing to review.',
        'overdue' => 'Overdue',
    ],

    'recent_submissions' => [
        'heading' => 'Recent submissions',
        'empty' => 'No submissions in this period.',
    ],

    'workflow_failures' => [
        'heading' => 'Workflow failures',
        'empty' => 'No failed workflow runs in this period.',
    ],

    'draft_forms' => [
        'heading' => 'Draft forms',
        'empty' => 'No unpublished forms.',
    ],

    'recently_published' => [
        'heading' => 'Recently published',
        'empty' => 'No versions published in this period.',
    ],

    'columns' => [
        'number' => 'Number',
        'form' => 'Form',
        'status' => 'Status',
        'submitter' => 'Submitted by',
        'submitted_at' => 'Submitted',
        'due_at' => 'Due',
        'created_at' => 'Requested',
        'workflow' => 'Workflow',
        'error' => 'Error',
        'failed_at' => 'Failed at',
        'updated_at' => 'Last edited',
        'version' => 'Version',
        'publisher' => 'Published by',
        'published_at' => 'Published',
    ],
];
