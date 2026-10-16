import { describe, expect, it } from 'vitest';
import { DeviceWipedError, ReauthRequiredError } from '../../src/lib/api/errors.ts';
import {
    noticeFor,
    onAuthLost,
    rememberNotice,
    reportAuthError,
    resetAuthNotice,
} from '../../src/lib/session/auth-events.ts';
import { buildEvaluationContext, zonedNow } from '../../src/lib/session/eval-context.ts';
import { getOrganizationId, setOrganizationId } from '../../src/lib/session/org-store.ts';
import { resolveOrganization } from '../../src/lib/session/organization.ts';
import { ORG_A, ORG_B, makeMe } from '../utils/fixtures.ts';
import { setupTestServer } from '../utils/server.ts';

setupTestServer();

describe('zonedNow', () => {
    const instant = new Date('2026-10-16T22:30:15.500Z');

    it('formats today/now in the organization time zone with its offset', () => {
        expect(zonedNow('Europe/Berlin', instant)).toEqual({
            today: '2026-10-17',
            now: '2026-10-17T00:30:15+02:00',
        });
        expect(zonedNow('America/St_Johns', instant)).toEqual({
            today: '2026-10-16',
            now: '2026-10-16T20:00:15-02:30',
        });
        expect(zonedNow('UTC', instant).now).toBe('2026-10-16T22:30:15+00:00');
    });

    it('falls back to the device zone for invalid or missing zones', () => {
        expect(zonedNow('Not/AZone', instant).today).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(zonedNow(null, instant).now).toMatch(/[+-]\d{2}:\d{2}$/);
    });

    it('builds the evaluation context', () => {
        const context = buildEvaluationContext(makeMe(), ORG_A, 3, instant);
        expect(context).toEqual({
            today: '2026-10-17',
            now: '2026-10-17T00:30:15+02:00',
            user: { name: 'Willi Worker', email: 'worker@demo.test' },
            org: { name: 'Demo GmbH' },
            formVersion: 3,
        });
        expect(buildEvaluationContext(makeMe(), null, 1, instant).org).toEqual({ name: '' });
    });
});

describe('organization', () => {
    it('auto-selects a single organization', () => {
        expect(resolveOrganization(makeMe([ORG_A]))).toEqual(ORG_A);
        expect(getOrganizationId()).toBe(1);
    });

    it('keeps a valid stored choice and drops an invalid one', () => {
        setOrganizationId(2);
        expect(resolveOrganization(makeMe([ORG_A, ORG_B]))?.id).toBe(2);
        setOrganizationId(99);
        expect(resolveOrganization(makeMe([ORG_A, ORG_B]))).toBeNull();
        expect(getOrganizationId()).toBeNull();
        expect(resolveOrganization(makeMe([]))).toBeNull();
    });
});

describe('auth events', () => {
    it('maps errors to login notices and notifies listeners', () => {
        expect(noticeFor(new DeviceWipedError('x'))).toBe('wiped');
        expect(noticeFor(new ReauthRequiredError('x', false))).toBeUndefined();
        expect(noticeFor(new ReauthRequiredError('x'))).toBe('reauth');
        const seen: unknown[] = [];
        const stop = onAuthLost((notice) => seen.push(notice));
        expect(reportAuthError(new Error('other'))).toBe(false);
        expect(reportAuthError(new DeviceWipedError('x'))).toBe(true);
        stop();
        reportAuthError(new DeviceWipedError('x'));
        expect(seen).toEqual(['wiped']);
    });

    it('keeps the strongest notice until the next login', () => {
        const seen: unknown[] = [];
        const stop = onAuthLost((notice) => seen.push(notice));
        reportAuthError(new ReauthRequiredError('x', false));
        reportAuthError(new ReauthRequiredError('x'));
        reportAuthError(new DeviceWipedError('x'));
        reportAuthError(new ReauthRequiredError('x', false));
        reportAuthError(new ReauthRequiredError('x'));
        expect(rememberNotice('logout')).toBe('wiped');
        resetAuthNotice();
        expect(rememberNotice('logout')).toBe('logout');
        reportAuthError(new ReauthRequiredError('x'));
        stop();
        expect(seen).toEqual([undefined, 'reauth', 'wiped', 'wiped', 'wiped', 'logout']);
    });
});
