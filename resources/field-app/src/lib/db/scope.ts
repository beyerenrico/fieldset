/**
 * The signed-in user and current organization as an offline-store Scope (ADR 0035). The session code
 * calls setCurrentUserId() when `/me` resolves (online or from the cached session) and null on logout.
 */
import { getOrganizationId } from '../session/org-store.ts';
import type { Scope } from './schema.ts';

let currentUserId: number | null = null;

export function setCurrentUserId(userId: number | null): void {
    currentUserId = userId;
}

export function getCurrentUserId(): number | null {
    return currentUserId;
}

/** The scope new rows are written with; null before login or without an organization. */
export function currentScope(): Scope | null {
    const orgId = getOrganizationId();
    return currentUserId === null || orgId === null ? null : { orgId, userId: currentUserId };
}
