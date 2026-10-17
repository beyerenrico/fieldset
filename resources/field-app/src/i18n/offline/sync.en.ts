/** M6 sync texts (owned by agent A, ADR 0035). Keys must match sync.de.ts. */
export const syncEn = {
    'sync.queued': 'Waiting to sync',
    'sync.savedLocal': 'Saved on this device',
    'sync.state.local': 'Only on this device',
    'sync.state.queued': 'Waiting to sync',
    'sync.state.syncing': 'Syncing…',
    'sync.state.synced': 'Synced',
    'sync.state.conflict': 'Conflict',
    'sync.state.blocked': 'Needs your decision',
    'sync.submitQueued.title': 'Submission queued',
    'sync.submitQueued.text':
        'It is sent automatically as soon as you are online. The number is assigned then.',
    'sync.submitQueued.retry':
        'The last attempt failed: {message}. It is tried again automatically.',
    'sync.submitQueued.editing':
        'This submission is queued. Changes you make now are sent with it.',
    'sync.send.queued':
        'Sending to {email} is queued. It goes out after sync, once the number and the PDF exist.',
    'sync.send.blocked': 'Sending to {email} failed: {message}',
    'sync.send.remove': 'Remove from queue',
    'sync.blocked.not_editable':
        'This submission can no longer be edited on the server. Your changes are kept on this device.',
    'sync.blocked.version_retired':
        'This form version was retired. Your entries can be copied to the current version.',
    'sync.blocked.version_mismatch': 'This draft belongs to another form version on the server.',
    'sync.blocked.form_access_revoked': 'You no longer have access to this form.',
    'sync.blocked.forbidden': 'You may not change this submission.',
    'sync.blocked.not_found': 'This submission no longer exists on the server.',
    'sync.blocked.payload_too_large': 'This draft is too large to be saved on the server.',
    'sync.blocked.other': 'The server did not accept this draft ({code}).',
    'sync.blocked.title': 'Not synced',
    'sync.attachment.failed': 'An attachment was refused. Replace it, then submit again.',
    'sync.action.retry': 'Try again',
    'sync.action.discard': 'Discard my changes',
    'sync.action.discardConfirm': 'Really discard? This cannot be undone.',
    'sync.action.discardYes': 'Discard',
    'sync.action.cancel': 'Cancel',
    'sync.action.saveAsNew': 'Save as a new draft',
    'sync.action.copyToCurrent': 'Copy to the current version',
    'sync.discarded': 'Your local changes were discarded.',
    'sync.copied': 'Your entries were copied into a new draft.',
    'sync.home.offline': 'Offline: showing the drafts on this device.',
    'sync.list.title': 'On this device',
    'sync.list.numberPending': 'Number pending',
} as const;
