/** M6 account texts, German (owned by agent C, ADR 0035). */
import type { accountEn } from './account.en.ts';

export const accountDe: Readonly<Record<keyof typeof accountEn, string>> = {
    'account.storage': 'Speicher auf diesem Gerät',
    'account.usage': '{used} von {quota} belegt',
    'account.usageUnknown': 'Dieser Browser zeigt die Speicherbelegung nicht an.',
    'account.persisted': 'Der Browser behält diese Daten (dauerhafter Speicher).',
    'account.notPersisted': 'Der Browser kann diese Daten löschen, wenn der Speicher knapp wird.',
    'account.persistUnknown': 'Dieser Browser gibt nicht an, ob er die Daten behält.',
    'account.syncTitle': 'Synchronisierung',
    'account.lastSync': 'Zuletzt synchronisiert: {time}',
    'account.neverSynced': 'Von diesem Gerät noch nicht synchronisiert.',
    'account.allSynced': 'Alles auf diesem Gerät ist synchronisiert.',
    'account.countDrafts': 'Nicht synchronisierte Entwürfe',
    'account.countOps': 'Änderungen, die noch gesendet werden',
    'account.countAttachments': 'Nicht hochgeladene Fotos und Dateien',
    'account.countBlocked': 'Brauchen Ihre Entscheidung',
    'account.syncNow': 'Jetzt synchronisieren',
    'account.syncing': 'Synchronisiere …',
    'account.syncDone': 'Alles ist synchronisiert.',
    'account.syncRemaining': 'Warten noch auf die Synchronisierung: {count}',
    'account.syncBlocked': 'Brauchen Ihre Entscheidung, bevor sie synchronisiert werden: {count}',
    'account.syncOffline':
        'Sie sind offline. Ihre Daten bleiben auf diesem Gerät und werden synchronisiert, sobald Sie online sind.',
    'account.syncReauth':
        'Ihre Sitzung ist abgelaufen. Melden Sie sich erneut an, um zu synchronisieren.',
    'account.syncBusy': 'Eine Synchronisierung läuft bereits.',
    'account.syncError':
        'Die Synchronisierung ist fehlgeschlagen. Ihre Daten bleiben auf diesem Gerät – versuchen Sie es später erneut.',
    'account.reauthHint':
        'Ihre Sitzung ist abgelaufen. Melden Sie sich erneut an, um zu synchronisieren – Ihre Daten bleiben auf diesem Gerät.',
    'account.signInAgain': 'Erneut anmelden',
    'account.badgePending': 'Nicht synchronisierte Einreichungen: {count}',
    'account.badgeBlocked': 'Brauchen Ihre Entscheidung: {count}',
    'account.badgeReauth': 'Erneut anmelden, um zu synchronisieren',
    'account.logoutUnsynced':
        'Noch nicht synchronisierte Einreichungen: {count}. Beim Abmelden werden sie gelöscht.',
    'account.foreignTitle': 'Daten eines anderen Benutzers',
    'account.foreignText':
        'Auf diesem Gerät liegen noch nicht synchronisierte Einträge eines anderen Benutzers ({count}). Sie bleiben verborgen und werden nie mit Ihrem Konto gesendet.',
    'account.foreignKeep': 'Behalten',
    'account.foreignDelete': 'Löschen',
    'account.noOrganizations':
        'Ihr Konto gehört zu keiner aktiven Organisation. Bitte wenden Sie sich an Ihre Administration.',
};
