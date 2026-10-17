/** M6 sync texts (owned by agent A, ADR 0035). Keys must match sync.de.ts. */
export const syncEn = {
    'sync.savedLocal': 'Saved on this device',
    'sync.state.local': 'Only on this device',
    'sync.state.queued': 'Waiting to sync',
    'sync.state.syncing': 'Syncing…',
    'sync.state.synced': 'Synced',
    'sync.state.conflict': 'Conflict',
    'sync.state.blocked': 'Needs your decision',
    'sync.submitQueued.title': 'Ready to send',
    'sync.submitQueued.text':
        'It is sent automatically as soon as you are online. The number is assigned then.',
    'sync.submitQueued.editing':
        'This submission is ready to send. Changes you make now are sent with it.',
    'sync.retry.temporary':
        'The last attempt failed because of a temporary problem. It is tried again automatically.',
    'sync.retry.timeout':
        'The last attempt got no answer in time. It is tried again automatically.',
    'sync.retry.busy':
        'The last attempt was turned away because of too many requests. It is tried again automatically.',
    'sync.send.queued':
        'Sending to {email} is queued. It goes out after sync, once the number and the PDF exist.',
    'sync.send.blocked': 'Not sent to {email}.',
    'sync.send.reason.not_submitted':
        'The form has not been submitted yet. Submit it first, then try again.',
    'sync.send.reason.invalid':
        'The email address was not accepted. Cancel sending and send again with a corrected address.',
    'sync.send.reason.other': 'The office did not accept the request. Try again later.',
    'sync.send.remove': 'Cancel sending',
    'sync.blocked.title': 'Needs your decision',
    'sync.blocked.not_editable':
        'This submission can no longer be changed – it has already been submitted or is being processed. Your entries stay on this device: save them as a new draft or discard them.',
    'sync.blocked.version_retired':
        'This form version was retired. Your entries stay on this device – copy them to the current version and check them there.',
    'sync.blocked.version_mismatch':
        'In the office, this draft belongs to another form version. Your entries stay on this device – save them as a new draft.',
    'sync.blocked.form_access_revoked':
        'You no longer have access to this form. Your entries stay on this device – ask your office to restore access, then tap Try again.',
    'sync.blocked.forbidden':
        'You may not change this submission. Your entries stay on this device – ask your office for access, then tap Try again.',
    'sync.blocked.not_found':
        'This submission no longer exists in the office. Your entries stay on this device – save them as a new draft.',
    'sync.blocked.payload_too_large':
        'This draft is too large to send. Remove large photos or files, then tap Try again.',
    'sync.blocked.other':
        'The office did not accept this draft. Your entries stay on this device – tap Try again later, or ask your office for help.',
    'sync.blocked.conflict':
        'Someone else changed this submission. Choose which version to keep before it can be sent.',
    'sync.refused.fields.one':
        'Not submitted: 1 field needs fixing. Open the form, correct the marked field and submit again.',
    'sync.refused.fields.other':
        'Not submitted: {count} fields need fixing. Open the form, correct the marked fields and submit again.',
    'sync.refused.fields.some':
        'Not submitted: some fields need fixing. Open the form, correct the marked fields and submit again.',
    'sync.refused.attachment':
        'Not submitted: an attachment is missing. Open the form, add it again and submit.',
    'sync.refused.attachmentBlocked':
        'The attachment “{name}” was not accepted. {reason} Open the form and replace it.',
    'sync.refused.open': 'Open the form',
    'sync.decision.resolve': 'Resolve',
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
