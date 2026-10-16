/** Notification links: Field App paths open in the app, http(s) elsewhere externally, rest ignored. */
import { describe, expect, it } from 'vitest';
import { notificationTarget } from '../../src/lib/notifications/target.ts';

const ORIGIN = 'http://localhost:3000';

describe('notification targets', () => {
    it.each([
        ['/app/submissions/u1', { kind: 'app', href: '/app/submissions/u1' }],
        ['/app', { kind: 'app', href: '/app' }],
        [
            `${ORIGIN}/app/submissions/u1?x=1#top`,
            { kind: 'app', href: '/app/submissions/u1?x=1#top' },
        ],
        [
            `${ORIGIN}/admin/1/submissions/5`,
            { kind: 'external', href: `${ORIGIN}/admin/1/submissions/5` },
        ],
        ['/application', { kind: 'external', href: `${ORIGIN}/application` }],
        ['https://example.test/app/x', { kind: 'external', href: 'https://example.test/app/x' }],
    ])('%s → %o', (url, expected) => {
        expect(notificationTarget(url, ORIGIN)).toEqual(expected);
    });

    it.each([null, '', '   ', 'javascript:alert(1)', 'data:text/html,hi', 'http://[::1'])(
        'ignores %s',
        (url) => {
            expect(notificationTarget(url, ORIGIN)).toBeNull();
        },
    );

    it('defaults to the page origin', () => {
        expect(notificationTarget('/app/forms')).toEqual({ kind: 'app', href: '/app/forms' });
    });
});
