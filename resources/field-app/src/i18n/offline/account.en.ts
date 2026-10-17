/** M6 account texts (owned by agent C, ADR 0035). Keys must match account.de.ts. */
export const accountEn = {
    'account.storage': 'Storage on this device',
    'account.usage': '{used} of {quota} used',
    'account.usageUnknown': 'This browser does not report the storage usage.',
    'account.persisted': 'The browser keeps this data (persistent storage).',
    'account.notPersisted': 'The browser may delete this data when storage runs low.',
    'account.persistUnknown': 'This browser does not say whether it keeps the data.',
    'account.keepData': 'Keep data on this device',
    'account.persistDenied': 'The browser did not allow it. Installing the app can help.',
    'account.storageAlmostFull': 'Storage almost full – sync now to free space.',
    'account.syncTitle': 'Sync',
    'account.lastSync': 'Last synced: {time}',
    'account.neverSynced': 'Not synced from this device yet.',
    'account.allSynced': 'Everything on this device is synced.',
    'account.countSubmissions': 'Submissions not synced',
    'account.countAttachments': 'Photos and files not uploaded',
    'account.countBlocked': 'Need your decision',
    'account.syncNow': 'Sync now',
    'account.syncing': 'Syncing…',
    'account.syncDone': 'Everything is synced.',
    'account.syncRemaining':
        'Some data is still waiting to sync. The next attempt starts by itself.',
    'account.syncBlocked': 'Need your decision before they can sync: {count}',
    'account.syncOffline':
        'You are offline. Your data stays on this device and syncs when you are online.',
    'account.syncReauth': 'Your session has ended. Sign in again to sync.',
    'account.syncBusy': 'A sync is already running.',
    'account.syncError': 'Sync failed. Your data stays on this device – try again later.',
    'account.reauthHint':
        'Your session has ended. Sign in again to sync – your data stays on this device.',
    'account.signInAgain': 'Sign in again',
    'account.badgePending': 'Submissions not synced: {count}',
    'account.badgeBlocked': 'Need your decision: {count}',
    'account.badgeReauth': 'Sign in again to sync',
    'account.logoutUnsynced': 'Submissions not synced yet: {count}. Signing out deletes them.',
    'account.logoutPending':
        'You are signed out on this device. The sign-out on the server is completed as soon as you are online.',
    'account.foreignTitle': 'Data of another user',
    'account.foreignText':
        'This device still holds unsynced submissions of another user ({count}). They stay hidden and are never sent with your account. Keep them if that person will sign in here again to send them. Signing out deletes them.',
    'account.foreignKeep': 'Keep them',
    'account.foreignDelete': 'Delete them',
    'account.foreignConfirmTitle': 'Delete the data of another user?',
    'account.foreignConfirmText':
        'Submissions of another user: {count}. They were never sent and cannot be recovered once deleted.',
    'account.foreignConfirmDelete': 'Yes, delete them',
    'account.foreignBack': 'Back',
    'account.noOrganizations':
        'Your account does not belong to an active organization. Please contact your administrator.',
} as const;
