/**
 * Attachments the server refused (ADR 0035): the AttachmentRow keeps its `error`, so screens can
 * name the file and the reason. Read only for submissions blocked by `attachment_blocked`.
 */
import { useT } from '../../i18n/I18nProvider.tsx';
import { db } from '../../lib/db/db.ts';
import { useLiveQuery } from '../../lib/db/live.ts';
import type { OutboxError, Scope } from '../../lib/db/schema.ts';
import { inScope } from '../../lib/drafts/local-drafts.ts';

export interface AttachmentProblem {
    readonly submissionUuid: string;
    readonly fileName: string;
    readonly error: OutboxError;
}

const NONE: ReadonlyMap<string, AttachmentProblem> = new Map();

/** The first refused attachment per submission (live); empty while loading or without uuids. */
export function useAttachmentProblems(
    uuids: readonly string[],
    scope: Scope | null,
): ReadonlyMap<string, AttachmentProblem> {
    const key = uuids.toSorted().join(',');
    const problems = useLiveQuery(async () => {
        if (scope === null || uuids.length === 0) {
            return NONE;
        }
        const rows = await db.attachments.where('submissionUuid').anyOf(uuids).toArray();
        const entries = rows.flatMap((row): [string, AttachmentProblem][] =>
            row.error !== null && inScope(row, scope)
                ? [
                      [
                          row.submissionUuid,
                          {
                              submissionUuid: row.submissionUuid,
                              fileName: row.fileName,
                              error: row.error,
                          },
                      ],
                  ]
                : [],
        );
        return new Map(entries.toReversed()); // the first one per submission wins
    }, [key, scope?.orgId, scope?.userId]);
    return problems ?? NONE;
}

const TOO_LARGE: ReadonlySet<string> = new Set(['file_too_large', 'payload_too_large']);

/** The localized reason of a refused upload (never the server's own message). */
export function useAttachmentReason(): (error: OutboxError) => string {
    const t = useT();
    return (error) => {
        if (TOO_LARGE.has(error.code) || error.status === 413) {
            return t('upload.file_too_large');
        }
        return error.code === 'file_type_not_allowed'
            ? t('upload.file_type_not_allowed')
            : t('upload.failed');
    };
}
