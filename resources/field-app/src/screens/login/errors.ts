import type { Translate } from '../../i18n/I18nProvider.tsx';
import { ApiError, NetworkError, validationDetails } from '../../lib/api/errors.ts';

export function authErrorMessage(
    error: unknown,
    t: Translate,
    invalid: 'credentials' | 'code',
): string {
    if (error instanceof NetworkError) {
        return t('error.offline');
    }
    if (error instanceof ApiError && error.status === 422) {
        return invalid === 'code' ? t('auth.invalidCode') : t('auth.invalid');
    }
    if (error instanceof ApiError && error.status === 429) {
        return t('auth.throttled');
    }
    return t('error.generic');
}

/** Fortify refused the password: the email's organization enforces SSO (422 `errors.sso_required`). */
export function isSsoRequired(error: unknown): boolean {
    return validationDetails(error).some((detail) => detail.pointer === '/sso_required');
}

/** Home-realm discovery needs the network; an offline start from the cached session does not. */
export function discoveryErrorMessage(error: unknown, t: Translate): string {
    if (error instanceof NetworkError) {
        return t('auth.discoverOffline');
    }
    if (error instanceof ApiError && error.status === 422) {
        return t('auth.emailInvalid');
    }
    if (error instanceof ApiError && error.status === 429) {
        return t('auth.throttled');
    }
    return t('error.generic');
}
