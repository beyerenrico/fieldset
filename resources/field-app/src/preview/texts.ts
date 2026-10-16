/** The few texts of the preview shell (the renderer brings its own). */
const texts = {
    en: {
        waiting: 'Waiting for a form definition…',
        invalid: 'The form definition cannot be shown.',
        check: 'Check entries',
        reset: 'Clear entries',
    },
    de: {
        waiting: 'Warte auf eine Formulardefinition…',
        invalid: 'Die Formulardefinition kann nicht angezeigt werden.',
        check: 'Eingaben prüfen',
        reset: 'Eingaben löschen',
    },
} as const;

export type PreviewTexts = (typeof texts)['en'];

export function previewTexts(locale: string | undefined): PreviewTexts {
    return (locale ?? '').toLowerCase().startsWith('de') ? texts.de : texts.en;
}
