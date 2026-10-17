/** Full page loads after logout and wipe: nothing held in memory survives them (tests mock this). */
export const LOGIN_PATH = '/app/login';

/** Replaces the current page (no back navigation into the signed-out app). */
export function hardReload(url: string): void {
    window.location.replace(url);
}
