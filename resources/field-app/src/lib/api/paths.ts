/** Every backend path in one place (contract: docs/api/field-app-v1.md). */
export const API_BASE = '/api/v1';
export const CSRF_COOKIE_PATH = '/sanctum/csrf-cookie';
/** Fortify web routes (root, prefix `auth`, not enveloped). */
export const AUTH_BASE = '/auth';

const enc = encodeURIComponent;

export const paths = {
    login: `${AUTH_BASE}/login`,
    twoFactorChallenge: `${AUTH_BASE}/two-factor-challenge`,
    logout: `${AUTH_BASE}/logout`,
    deviceStatus: `${API_BASE}/auth/device-status`,
    me: `${API_BASE}/me`,
    devices: `${API_BASE}/devices`,
    forms: `${API_BASE}/forms`,
    formVersion: (formId: number, versionId: number): string =>
        `${API_BASE}/forms/${enc(String(formId))}/versions/${enc(String(versionId))}`,
    syncManifest: `${API_BASE}/sync/manifest`,
    submissions: `${API_BASE}/submissions`,
    submission: (uuid: string): string => `${API_BASE}/submissions/${enc(uuid)}`,
    attachments: (uuid: string): string => `${API_BASE}/submissions/${enc(uuid)}/attachments`,
    attachment: (uuid: string, attachmentUuid: string): string =>
        `${API_BASE}/submissions/${enc(uuid)}/attachments/${enc(attachmentUuid)}`,
    submit: (uuid: string): string => `${API_BASE}/submissions/${enc(uuid)}/submit`,
    document: (uuid: string): string => `${API_BASE}/submissions/${enc(uuid)}/document`,
    send: (uuid: string): string => `${API_BASE}/submissions/${enc(uuid)}/send`,
    tasks: `${API_BASE}/tasks`,
    completeTask: (id: number): string => `${API_BASE}/tasks/${enc(String(id))}/complete`,
    notifications: `${API_BASE}/notifications`,
    notificationsRead: `${API_BASE}/notifications/read`,
    notificationPreferences: `${API_BASE}/me/notification-preferences`,
} as const;
