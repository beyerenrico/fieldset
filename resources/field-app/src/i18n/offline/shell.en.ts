/** M6 shell texts (owned by agent B, ADR 0035). Keys must match shell.de.ts. */
export const shellEn = {
    'pwa.install': 'Install app',
    'pwa.install.text': 'Install the app to use it offline and from your home screen.',
    'pwa.install.ios': 'Tap Share (in Safari it may be under •••), then “Add to Home Screen”.',
    'pwa.install.dismiss': 'Not now',
    'pwa.ios.warning':
        'Submissions not synced yet: {count}. iOS deletes the data of web apps that are not on the home screen after 7 days without use.',
    'pwa.ios.hide': 'Hide',
    'pwa.update.available': 'A new version of the app is available.',
    'pwa.update.apply': 'Update now',
    'pwa.update.applying': 'Updating…',
    'pwa.update.later': 'Later',
    'pwa.db.outdated': 'The app was updated in another tab. Reload this page to continue.',
    'pwa.db.blocked': 'Close the other tabs of the app, then reload this page.',
    'pwa.reload': 'Reload',
    /** Header: the browser reports a network, but the server could not be reached (lie-fi). */
    'shell.noConnection': 'No connection',
    /** A route that needs the server, while offline (not an error). */
    'shell.routeOffline': 'You are offline. This page needs a connection.',
    'catalog.offlineCached': 'You are offline. These are the forms saved on this device.',
    'catalog.notCached':
        'The forms are not available offline yet. Connect to the internet once to download them.',
    'catalog.formNotCached': 'Not available offline',
    /** For the fill/detail screens when getFormVersion raises NotCachedError. */
    'catalog.versionNotCached':
        'This form version is not available offline yet. Connect to the internet once to download it.',
} as const;
