<?php

declare(strict_types=1);

return [
    'status' => [
        'draft' => 'Draft', 'submitted' => 'Submitted', 'in_review' => 'In review', 'changes_requested' => 'Changes requested',
        'approved' => 'Approved', 'completed' => 'Completed', 'void' => 'Void',
    ],
    'errors' => [
        'invalid_transition' => 'A submission cannot go from :from to :to.',
        'revision_conflict' => 'This draft was changed elsewhere. Choose which version to keep.',
        'not_editable' => 'This submission can no longer be edited.',
        'form_access_revoked' => 'You no longer have access to this form. Contact your office.',
        'version_retired' => 'This form version was retired. Restart the form on the latest version.',
        'version_mismatch' => 'This draft belongs to another form version. Reload it before saving.',
        'attachments_missing' => 'Some photos or files have not been uploaded yet.',
        'attachment_conflict' => 'This attachment id belongs to another submission.',
        'file_too_large' => 'The file is too large.',
        'file_type_not_allowed' => 'This file type is not allowed.',
        'image_too_large' => 'The image dimensions are too large. Use a smaller photo.',
        'idempotency_key_required' => 'Send an Idempotency-Key header.',
        'idempotency_key_reused' => 'This Idempotency-Key was already used for a different request.',
        'device_unknown' => 'Unknown device.',
        'device_revoked' => 'This device was signed out.',
    ],
];
