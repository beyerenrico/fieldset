/** M6 sync texts, German (owned by agent A, ADR 0035). */
import type { syncEn } from './sync.en.ts';

export const syncDe: Readonly<Record<keyof typeof syncEn, string>> = {
    'sync.queued': 'Wartet auf Synchronisierung',
    'sync.savedLocal': 'Auf diesem Gerät gespeichert',
    'sync.state.local': 'Nur auf diesem Gerät',
    'sync.state.queued': 'Wartet auf Synchronisierung',
    'sync.state.syncing': 'Wird synchronisiert …',
    'sync.state.synced': 'Synchronisiert',
    'sync.state.conflict': 'Konflikt',
    'sync.state.blocked': 'Entscheidung nötig',
    'sync.submitQueued.title': 'Einreichung vorgemerkt',
    'sync.submitQueued.text':
        'Sie wird automatisch gesendet, sobald Sie online sind. Die Nummer wird dann vergeben.',
    'sync.submitQueued.retry':
        'Der letzte Versuch ist fehlgeschlagen: {message}. Er wird automatisch wiederholt.',
    'sync.submitQueued.editing':
        'Diese Einreichung ist vorgemerkt. Änderungen, die Sie jetzt machen, werden mitgesendet.',
    'sync.send.queued':
        'Der Versand an {email} ist vorgemerkt. Er erfolgt nach der Synchronisierung, sobald Nummer und PDF vorliegen.',
    'sync.send.blocked': 'Der Versand an {email} ist fehlgeschlagen: {message}',
    'sync.send.remove': 'Aus der Warteschlange entfernen',
    'sync.blocked.not_editable':
        'Diese Einreichung kann auf dem Server nicht mehr bearbeitet werden. Ihre Änderungen bleiben auf diesem Gerät.',
    'sync.blocked.version_retired':
        'Diese Formularversion wurde zurückgezogen. Ihre Eingaben können in die aktuelle Version übernommen werden.',
    'sync.blocked.version_mismatch':
        'Dieser Entwurf gehört auf dem Server zu einer anderen Formularversion.',
    'sync.blocked.form_access_revoked': 'Sie haben keinen Zugriff mehr auf dieses Formular.',
    'sync.blocked.forbidden': 'Sie dürfen diese Einreichung nicht ändern.',
    'sync.blocked.not_found': 'Diese Einreichung gibt es auf dem Server nicht mehr.',
    'sync.blocked.payload_too_large':
        'Dieser Entwurf ist zu groß, um auf dem Server gespeichert zu werden.',
    'sync.blocked.other': 'Der Server hat diesen Entwurf nicht angenommen ({code}).',
    'sync.blocked.title': 'Nicht synchronisiert',
    'sync.attachment.failed':
        'Ein Anhang wurde abgelehnt. Ersetzen Sie ihn und reichen Sie dann erneut ein.',
    'sync.action.retry': 'Erneut versuchen',
    'sync.action.discard': 'Meine Änderungen verwerfen',
    'sync.action.discardConfirm': 'Wirklich verwerfen? Das kann nicht rückgängig gemacht werden.',
    'sync.action.discardYes': 'Verwerfen',
    'sync.action.cancel': 'Abbrechen',
    'sync.action.saveAsNew': 'Als neuen Entwurf speichern',
    'sync.action.copyToCurrent': 'In die aktuelle Version übernehmen',
    'sync.discarded': 'Ihre lokalen Änderungen wurden verworfen.',
    'sync.copied': 'Ihre Eingaben wurden in einen neuen Entwurf übernommen.',
    'sync.home.offline': 'Offline: Es werden die Entwürfe auf diesem Gerät angezeigt.',
    'sync.list.title': 'Auf diesem Gerät',
    'sync.list.numberPending': 'Nummer ausstehend',
};
