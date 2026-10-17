/** Search-param parsers for the routes (untrusted input → narrow types). */
import {
    SSO_ERROR_CODES,
    SUBMISSION_STATUSES,
    type SsoErrorCode,
    type SubmissionStatus,
} from '../lib/api/types.ts';
import type { AuthNotice } from '../lib/session/auth-events.ts';

type Raw = Record<string, unknown>;

function positiveInt(value: unknown): number | undefined {
    const number = typeof value === 'string' ? Number(value) : value;
    return typeof number === 'number' && Number.isInteger(number) && number > 0
        ? number
        : undefined;
}

export interface LoginSearch {
    readonly notice?: AuthNotice;
    /** Back from a successful SSO login (`?sso=ok`): finish the sign-in on this device. */
    readonly sso?: 'ok';
    /** Back from a refused SSO login; an unknown code reads as `sso_failed`. */
    readonly sso_error?: SsoErrorCode;
}

function parseSsoError(value: unknown): SsoErrorCode | undefined {
    if (typeof value !== 'string' || value === '') {
        return undefined;
    }
    return SSO_ERROR_CODES.find((code) => code === value) ?? 'sso_failed';
}

export function parseLoginSearch(search: Raw): LoginSearch {
    const notice = search.notice;
    const sso = search.sso === 'ok' ? 'ok' : undefined;
    const ssoError = sso === undefined ? parseSsoError(search.sso_error) : undefined;
    return {
        ...(notice === 'reauth' || notice === 'wiped' || notice === 'logout' ? { notice } : {}),
        ...(sso === undefined ? {} : { sso }),
        ...(ssoError === undefined ? {} : { sso_error: ssoError }),
    };
}

export interface FillSearch {
    readonly formId?: number;
    readonly versionId?: number;
}

export function parseFillSearch(search: Raw): FillSearch {
    const formId = positiveInt(search.formId);
    const versionId = positiveInt(search.versionId);
    return formId !== undefined && versionId !== undefined ? { formId, versionId } : {};
}

export interface SubmissionsSearch {
    readonly status?: SubmissionStatus;
}

export function parseSubmissionsSearch(search: Raw): SubmissionsSearch {
    const status = SUBMISSION_STATUSES.find((candidate) => candidate === search.status);
    return status === undefined ? {} : { status };
}

export interface DetailSearch {
    readonly submitted?: boolean;
    /** The submit was a resubmit after "changes requested" (number kept). */
    readonly resubmitted?: boolean;
}

const isTrue = (value: unknown): boolean => value === true || value === 'true';

export function parseDetailSearch(search: Raw): DetailSearch {
    if (!isTrue(search.submitted)) {
        return {};
    }
    return isTrue(search.resubmitted)
        ? { submitted: true, resubmitted: true }
        : { submitted: true };
}
