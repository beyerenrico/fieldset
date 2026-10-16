/**
 * Where a notification's `url` leads: a Field App path opens in the app (router history), anything
 * else on http(s) opens externally in a new tab; other schemes (javascript:, data:) are ignored.
 */
export const APP_BASE = '/app';

export type NotificationTarget =
    | { readonly kind: 'app'; readonly href: string }
    | { readonly kind: 'external'; readonly href: string };

export function notificationTarget(
    url: string | null,
    origin: string = window.location.origin,
): NotificationTarget | null {
    if (url === null || url.trim() === '') {
        return null;
    }
    let parsed: URL;
    try {
        parsed = new URL(url, origin);
    } catch {
        return null;
    }
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
        return null;
    }
    const inApp = parsed.pathname === APP_BASE || parsed.pathname.startsWith(`${APP_BASE}/`);
    if (parsed.origin === origin && inApp) {
        return { kind: 'app', href: `${parsed.pathname}${parsed.search}${parsed.hash}` };
    }
    return { kind: 'external', href: parsed.href };
}
