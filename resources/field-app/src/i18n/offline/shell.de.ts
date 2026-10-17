/** M6 shell texts, German (owned by agent B, ADR 0035). */
import type { shellEn } from './shell.en.ts';

export const shellDe: Readonly<Record<keyof typeof shellEn, string>> = {
    'pwa.install': 'App installieren',
    'pwa.install.text':
        'Installieren Sie die App, um sie offline und vom Home-Bildschirm aus zu nutzen.',
    'pwa.install.ios':
        'Tippen Sie auf „Teilen“ (in Safari ggf. unter „•••“), dann auf „Zum Home-Bildschirm“.',
    'pwa.install.dismiss': 'Nicht jetzt',
    'pwa.ios.warning':
        'Noch nicht synchronisierte Einreichungen: {count}. iOS löscht die Daten von Web-Apps, die nicht auf dem Home-Bildschirm liegen, nach 7 Tagen ohne Nutzung.',
    'pwa.ios.hide': 'Ausblenden',
    'pwa.update.available': 'Eine neue Version der App ist verfügbar.',
    'pwa.update.apply': 'Jetzt aktualisieren',
    'pwa.update.applying': 'Wird aktualisiert …',
    'pwa.update.later': 'Später',
    'pwa.db.outdated':
        'Die App wurde in einem anderen Tab aktualisiert. Laden Sie diese Seite neu, um fortzufahren.',
    'pwa.db.blocked': 'Schließen Sie die anderen Tabs der App und laden Sie diese Seite neu.',
    'pwa.reload': 'Neu laden',
    'shell.noConnection': 'Keine Verbindung',
    'shell.routeOffline': 'Sie sind offline. Diese Seite benötigt eine Verbindung.',
    'catalog.offlineCached':
        'Sie sind offline. Das sind die auf diesem Gerät gespeicherten Formulare.',
    'catalog.notCached':
        'Die Formulare sind offline noch nicht verfügbar. Verbinden Sie sich einmal mit dem Internet, um sie herunterzuladen.',
    'catalog.formNotCached': 'Offline nicht verfügbar',
    'catalog.versionNotCached':
        'Diese Formularversion ist offline noch nicht verfügbar. Verbinden Sie sich einmal mit dem Internet, um sie herunterzuladen.',
};
