import { putSubmission } from '../api/endpoints.ts';
import type { SaveTransport } from './types.ts';

/** M2 transport: straight to `PUT /submissions/{uuid}` (keepalive for the page-hide save). */
export const apiTransport: SaveTransport = {
    save: ({ uuid, formVersionId, data, revision, keepalive }) =>
        putSubmission(
            uuid,
            { formVersionId, data, revision },
            keepalive === true ? { keepalive } : {},
        ),
};
