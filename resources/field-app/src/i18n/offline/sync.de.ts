/** M6 sync texts, German (owned by agent A, ADR 0035). */
import type { syncEn } from './sync.en.ts';

export const syncDe: Readonly<Record<keyof typeof syncEn, string>> = {
    'sync.savedLocal': 'Auf diesem Gerät gespeichert',
    'sync.state.local': 'Nur auf diesem Gerät',
    'sync.state.queued': 'Wartet auf Synchronisierung',
    'sync.state.syncing': 'Wird synchronisiert …',
    'sync.state.synced': 'Synchronisiert',
    'sync.state.conflict': 'Konflikt',
    'sync.state.blocked': 'Entscheidung nötig',
    'sync.submitQueued.title': 'Bereit zum Senden',
    'sync.submitQueued.text':
        'Sie wird automatisch gesendet, sobald Sie online sind. Die Nummer wird dann vergeben.',
    'sync.submitQueued.editing':
        'Diese Einreichung ist bereit zum Senden. Änderungen, die Sie jetzt vornehmen, werden mitgesendet.',
    'sync.retry.temporary':
        'Der letzte Versuch ist wegen einer vorübergehenden Störung fehlgeschlagen. Er wird automatisch wiederholt.',
    'sync.retry.timeout':
        'Auf den letzten Versuch kam keine rechtzeitige Antwort. Er wird automatisch wiederholt.',
    'sync.retry.busy':
        'Der letzte Versuch wurde wegen zu vieler Anfragen abgewiesen. Er wird automatisch wiederholt.',
    'sync.send.queued':
        'Der Versand an {email} ist vorgemerkt. Er erfolgt nach der Synchronisierung, sobald Nummer und PDF vorliegen.',
    'sync.send.blocked': 'Nicht an {email} gesendet.',
    'sync.send.reason.not_submitted':
        'Das Formular ist noch nicht eingereicht. Reichen Sie es zuerst ein und versuchen Sie es dann erneut.',
    'sync.send.reason.invalid':
        'Die E-Mail-Adresse wurde nicht angenommen. Brechen Sie den Versand ab und senden Sie erneut mit einer korrigierten Adresse.',
    'sync.send.reason.other':
        'Das Büro hat die Anfrage nicht angenommen. Versuchen Sie es später erneut.',
    'sync.send.remove': 'Versand abbrechen',
    'sync.blocked.title': 'Entscheidung nötig',
    'sync.blocked.not_editable':
        'Diese Einreichung kann nicht mehr geändert werden – sie wurde bereits eingereicht oder wird bearbeitet. Ihre Eingaben bleiben auf diesem Gerät: Speichern Sie sie als neuen Entwurf oder verwerfen Sie sie.',
    'sync.blocked.version_retired':
        'Diese Formularversion wurde zurückgezogen. Ihre Eingaben bleiben auf diesem Gerät – kopieren Sie sie in die aktuelle Version und prüfen Sie sie dort.',
    'sync.blocked.version_mismatch':
        'Im Büro gehört dieser Entwurf zu einer anderen Formularversion. Ihre Eingaben bleiben auf diesem Gerät – speichern Sie sie als neuen Entwurf.',
    'sync.blocked.form_access_revoked':
        'Sie haben keinen Zugriff mehr auf dieses Formular. Ihre Eingaben bleiben auf diesem Gerät – bitten Sie Ihr Büro, den Zugriff wiederherzustellen, und tippen Sie dann auf „Erneut versuchen“.',
    'sync.blocked.forbidden':
        'Sie dürfen diese Einreichung nicht ändern. Ihre Eingaben bleiben auf diesem Gerät – bitten Sie Ihr Büro um Zugriff und tippen Sie dann auf „Erneut versuchen“.',
    'sync.blocked.not_found':
        'Diese Einreichung gibt es im Büro nicht mehr. Ihre Eingaben bleiben auf diesem Gerät – speichern Sie sie als neuen Entwurf.',
    'sync.blocked.payload_too_large':
        'Dieser Entwurf ist zu groß zum Senden. Entfernen Sie große Fotos oder Dateien und tippen Sie dann auf „Erneut versuchen“.',
    'sync.blocked.other':
        'Das Büro hat diesen Entwurf nicht angenommen. Ihre Eingaben bleiben auf diesem Gerät – tippen Sie später auf „Erneut versuchen“ oder bitten Sie Ihr Büro um Hilfe.',
    'sync.blocked.conflict':
        'Jemand anderes hat diese Einreichung geändert. Wählen Sie, welche Version gilt, bevor sie gesendet werden kann.',
    'sync.refused.fields.one':
        'Nicht eingereicht: 1 Feld muss korrigiert werden. Öffnen Sie das Formular, korrigieren Sie das markierte Feld und reichen Sie erneut ein.',
    'sync.refused.fields.other':
        'Nicht eingereicht: {count} Felder müssen korrigiert werden. Öffnen Sie das Formular, korrigieren Sie die markierten Felder und reichen Sie erneut ein.',
    'sync.refused.fields.some':
        'Nicht eingereicht: Einige Felder müssen korrigiert werden. Öffnen Sie das Formular, korrigieren Sie die markierten Felder und reichen Sie erneut ein.',
    'sync.refused.attachment':
        'Nicht eingereicht: Ein Anhang fehlt. Öffnen Sie das Formular, fügen Sie ihn erneut hinzu und reichen Sie ein.',
    'sync.refused.attachmentBlocked':
        'Der Anhang „{name}“ wurde nicht angenommen. {reason} Öffnen Sie das Formular und ersetzen Sie ihn.',
    'sync.refused.open': 'Formular öffnen',
    'sync.decision.resolve': 'Klären',
    'sync.attachment.failed':
        'Ein Anhang wurde abgelehnt. Ersetzen Sie ihn und reichen Sie dann erneut ein.',
    'sync.action.retry': 'Erneut versuchen',
    'sync.action.discard': 'Meine Änderungen verwerfen',
    'sync.action.discardConfirm': 'Wirklich verwerfen? Das kann nicht rückgängig gemacht werden.',
    'sync.action.discardYes': 'Verwerfen',
    'sync.action.cancel': 'Abbrechen',
    'sync.action.saveAsNew': 'Als neuen Entwurf speichern',
    'sync.action.copyToCurrent': 'In aktuelle Version kopieren',
    'sync.discarded': 'Ihre lokalen Änderungen wurden verworfen.',
    'sync.copied': 'Ihre Eingaben wurden in einen neuen Entwurf übernommen.',
    'sync.home.offline': 'Offline: Es werden die Entwürfe auf diesem Gerät angezeigt.',
    'sync.list.title': 'Auf diesem Gerät',
    'sync.list.numberPending': 'Nummer ausstehend',
};
