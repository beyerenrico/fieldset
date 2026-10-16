import { putSubmission } from '../api/endpoints.ts';
import type { SaveTransport } from './types.ts';

/** M2 transport: straight to `PUT /submissions/{uuid}`. */
export const apiTransport: SaveTransport = {
    save: ({ uuid, formVersionId, data, revision }) =>
        putSubmission(uuid, { formVersionId, data, revision }),
};
