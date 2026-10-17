/** Wording fixed by the M6 gate review (F3 fixes 14 and 15): formal German, the wipe explained. */
import { describe, expect, it } from 'vitest';
import { de } from '../../../src/i18n/de.ts';
import { en } from '../../../src/i18n/en.ts';

describe('account texts', () => {
    it('explains who wiped the device and what to do', () => {
        expect(en['notice.wiped']).toBe(
            'Your administrator signed this device out and removed its local data. Sign in again or contact your office.',
        );
        expect(de['notice.wiped']).toBe(
            'Ihre Administration hat dieses Gerät abgemeldet und die lokalen Daten gelöscht. Melden Sie sich erneut an oder wenden Sie sich an Ihr Büro.',
        );
    });

    it('asks formally to sign in again in German', () => {
        expect(de['notice.reauth']).toContain('Melden Sie sich erneut an');
        expect(de['notice.reauth']).not.toContain('Bitte erneut anmelden');
    });

    it('names the Share button where Safari may hide it', () => {
        expect(en['pwa.install.ios']).toBe(
            'Tap Share (in Safari it may be under •••), then “Add to Home Screen”.',
        );
        expect(de['pwa.install.ios']).toBe(
            'Tippen Sie auf „Teilen“ (in Safari ggf. unter „•••“), dann auf „Zum Home-Bildschirm“.',
        );
    });
});
