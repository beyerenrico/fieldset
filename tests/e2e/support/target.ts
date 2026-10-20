import { test } from './test';

// The E2E target (plan U22, BUILD_PROMPT_V2 §11 dogfooding): the checkout's dev stack by default, or an `fsa` instance
// with E2E_TARGET=fsa (E2E_BASE_URL its URL, E2E_COMPOSE_PROJECT its Compose project for artisan, MAILPIT_URL and
// MAILPIT_AUTH its catcher). Journeys that need dev-only services or settings (Keycloak SSO, the SSO demo seed, open
// registration) keep running on the dev stack and skip with the reason on an instance.

/** True against the checkout's dev stack, false against an `fsa` instance (E2E_TARGET=fsa). */
export const ON_DEV_STACK = (process.env.E2E_TARGET ?? '') !== 'fsa';

/** Skips the calling test, describe block or file on an `fsa` instance: it needs a dev-only service or setting. */
export function devStackOnly(reason: string): void {
  test.skip(!ON_DEV_STACK, `dev stack only: ${reason}`);
}

/**
 * Request options for the mail catcher's API: MAILPIT_URL (default: the dev stack's Mailpit) and, for an instance's
 * catcher behind its UI login, MAILPIT_AUTH as `user:password` (never logged; `fsa config show --catcher-login`).
 */
export function mailpitOptions(): { baseURL: string; extraHTTPHeaders?: Record<string, string> } {
  const baseURL = process.env.MAILPIT_URL ?? 'http://127.0.0.1:18025';
  const auth = process.env.MAILPIT_AUTH ?? '';
  return auth === ''
    ? { baseURL }
    : { baseURL, extraHTTPHeaders: { Authorization: `Basic ${Buffer.from(auth).toString('base64')}` } };
}
