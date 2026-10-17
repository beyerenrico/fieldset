/**
 * Before the browser leaves for the organization's identity provider (ADR 0036): open drafts write their
 * pending edits on this device, and a sign-out the server has not confirmed is sent first (the SSO login
 * replaces that session anyway). Both are best effort; the redirect happens regardless.
 */
import { saveAndDisposeDraftSessions } from '../../lib/drafts/draft-session.ts';
import { flushPendingLogout } from '../../lib/session/pending-logout.ts';
import { leaveForSso } from './leave.ts';

export async function continueToSso(redirectUrl: string): Promise<void> {
    try {
        await saveAndDisposeDraftSessions();
    } catch (error) {
        console.warn('Saving open drafts before the SSO redirect failed', error);
    }
    await flushPendingLogout().catch(() => false);
    leaveForSso(redirectUrl);
}
