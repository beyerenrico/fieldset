import { isConflict } from '../api/errors.ts';
import type { Submission } from '../api/types.ts';

/** The server's copy attached to a `revision_conflict` (null for any other error). */
export function serverCopy(error: unknown): Submission | null {
    if (!isConflict(error, 'revision_conflict')) {
        return null;
    }
    const details = error.details;
    if (typeof details !== 'object' || details === null || !('submission' in details)) {
        return null;
    }
    const submission = details.submission;
    return typeof submission === 'object' && submission !== null
        ? (submission as Submission)
        : null;
}
