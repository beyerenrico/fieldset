import type { Translate } from '../../i18n/I18nProvider.tsx';
import { ApiError, NetworkError } from '../../lib/api/errors.ts';

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
