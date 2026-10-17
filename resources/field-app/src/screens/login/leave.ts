/** Leaves the app for the identity provider (a full page load; tests mock this). */
export function leaveForSso(url: string): void {
    window.location.assign(url);
}
