/**
 * Copy rules from the UX review (§9.1, §11): no "server" jargon in sync and conflict texts,
 * consistent ellipsis (EN "Loading…", DE "Wird geladen …"), the formal German "Sie", and the
 * texts of the review findings in both languages.
 */
import { describe, expect, it } from 'vitest';
import { de } from '../../src/i18n/de.ts';
import { en, type MessageKey } from '../../src/i18n/en.ts';
import { accountEn } from '../../src/i18n/offline/account.en.ts';
import { shellEn } from '../../src/i18n/offline/shell.en.ts';

/** Keys of the session, shell and notice texts belong to another area (agent F3). */
const OTHER: ReadonlySet<string> = new Set([...Object.keys(accountEn), ...Object.keys(shellEn)]);
const keys = (Object.keys(en) as MessageKey[]).filter(
    (key) => !OTHER.has(key) && !key.startsWith('notice.'),
);
const decisionKeys = keys.filter((key) => /^(sync|conflict|save)\./.test(key));

describe('copy', () => {
    it('does not say "server" in sync, conflict and save texts', () => {
        for (const key of decisionKeys) {
            expect(en[key], key).not.toMatch(/server/i);
            expect(de[key], key).not.toMatch(/server/i);
        }
    });

    it('spaces the ellipsis the same way everywhere', () => {
        for (const key of keys) {
            expect(en[key], key).not.toMatch(/\s…/);
            expect(de[key], key).not.toMatch(/\S…/);
        }
    });

    it('addresses German users formally and never with infinitive requests', () => {
        for (const key of keys) {
            expect(de[key], key).not.toMatch(/\b(du|dich|dir|dein\w*)\b/i);
            expect(de[key], key).not.toMatch(
                /Bitte (erneut|später|zuerst|den|die|das|einen?) \w+(en|ern)\./,
            );
        }
    });

    it('has the review texts in both languages', () => {
        expect(en['sync.blocked.title']).toBe('Needs your decision');
        expect(de['sync.blocked.title']).toBe('Entscheidung nötig');
        expect(en['sync.submitQueued.title']).toBe('Ready to send');
        expect(de['sync.submitQueued.title']).toBe('Bereit zum Senden');
        expect(en['sync.send.remove']).toBe('Cancel sending');
        expect(de['sync.send.remove']).toBe('Versand abbrechen');
        expect(de['sync.action.copyToCurrent']).toBe('In aktuelle Version kopieren');
        expect(en['conflict.server']).toBe('Office version');
        expect(de['conflict.server']).toBe('Version im Büro');
        expect(en['conflict.oneRow']).toBe('1 row');
        expect(de['home.offlineEmpty']).toBe(
            'Offline – auf diesem Gerät sind keine Entwürfe gespeichert.',
        );
        expect(de['submissions.offline']).toBe(
            'Offline – die vollständige Liste wird geladen, sobald Sie online sind.',
        );
        expect(en['review.saveFirst']).not.toMatch(/connection/i);
        expect(de['review.saveFirst']).not.toMatch(/Verbindung/);
    });
});
