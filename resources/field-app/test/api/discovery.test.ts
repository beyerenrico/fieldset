/** Untrusted SSO answers and return parameters are narrowed before use (M7, ADR 0036). */
import { describe, expect, it } from 'vitest';
import { parseDiscovery } from '../../src/lib/api/endpoints.ts';
import { ApiError } from '../../src/lib/api/errors.ts';
import { paths } from '../../src/lib/api/paths.ts';
import { parseLoginSearch } from '../../src/routes/search.ts';

describe('parseDiscovery', () => {
    it('reads an SSO answer and resolves a relative redirect against this origin', () => {
        expect(
            parseDiscovery({
                mode: 'sso',
                redirect_url: '/auth/sso/abc/redirect?client=app',
                organization: ' SSO Demo GmbH ',
                enforced: true,
            }),
        ).toEqual({
            mode: 'sso',
            redirect_url: 'http://localhost:3000/auth/sso/abc/redirect?client=app',
            organization: 'SSO Demo GmbH',
            enforced: true,
        });
    });

    it('treats a missing organization and enforcement as empty and false', () => {
        expect(parseDiscovery({ mode: 'sso', redirect_url: 'https://idp.example.com/x' })).toEqual({
            mode: 'sso',
            redirect_url: 'https://idp.example.com/x',
            organization: '',
            enforced: false,
        });
    });

    it.each([undefined, null, 'password', { mode: 'password' }, { mode: 'magic' }, []])(
        'falls back to a password for %j',
        (data) => {
            expect(parseDiscovery(data)).toEqual({ mode: 'password' });
        },
    );

    it.each(['javascript:alert(1)', 'data:text/html,x', '', 42, null])(
        'refuses an SSO answer with the redirect %j',
        (redirect) => {
            expect(() => parseDiscovery({ mode: 'sso', redirect_url: redirect })).toThrow(ApiError);
        },
    );

    it('posts to the Fortify-style web route at the site root', () => {
        expect(paths.ssoDiscover).toBe('/auth/sso/discover');
    });
});

describe('parseLoginSearch', () => {
    it('keeps the sign-in notice', () => {
        expect(parseLoginSearch({ notice: 'reauth' })).toEqual({ notice: 'reauth' });
    });

    it('reads the SSO return', () => {
        expect(parseLoginSearch({ sso: 'ok' })).toEqual({ sso: 'ok' });
        expect(parseLoginSearch({ sso: 'yes' })).toEqual({});
        expect(parseLoginSearch({ sso: 'ok', sso_error: 'not_member' })).toEqual({ sso: 'ok' });
    });

    it('narrows the error code and reads an unknown one as sso_failed', () => {
        expect(parseLoginSearch({ sso_error: 'not_member' })).toEqual({ sso_error: 'not_member' });
        expect(parseLoginSearch({ sso_error: '<script>' })).toEqual({ sso_error: 'sso_failed' });
        expect(parseLoginSearch({ sso_error: '' })).toEqual({});
        expect(parseLoginSearch({ sso_error: 7 })).toEqual({});
    });
});
