/** Submission detail "Document (PDF)" texts (§9.1), German. */
import type { documentEn } from './document.en.ts';

export const documentDe: Readonly<Record<keyof typeof documentEn, string>> = {
    'document.title': 'Dokument (PDF)',
    'document.loading': 'PDF wird geladen …',
    'document.ready': 'Das PDF ist fertig.',
    'document.open': 'PDF öffnen',
    'document.notReady': 'Das PDF wird erstellt …',
    'document.checkAgain': 'Erneut prüfen',
    'document.error': 'Das PDF konnte nicht geladen werden.',
    'document.offline': 'Keine Verbindung. Das PDF lässt sich öffnen, sobald Sie online sind.',
    'document.failed': 'Das PDF konnte nicht erstellt werden. Das Büro wurde informiert.',
    'document.anonymized':
        'Die PDF-Dateien wurden bei der Anonymisierung dieser Einreichung gelöscht.',
};
