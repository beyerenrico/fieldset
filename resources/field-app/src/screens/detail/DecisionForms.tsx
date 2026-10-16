/** Contents of the review dialogs: confirm an approval, or reject with a required comment. */
import { useEffect, useId, useRef, useState, type ReactNode, type SubmitEvent } from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { Label } from '../../components/ui/label.tsx';
import { Textarea } from '../../components/ui/textarea.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import type { MessageKey } from '../../i18n/en.ts';

/** Server limit of `comment` (docs/api/field-app-v1.md). */
export const COMMENT_MAX_LENGTH = 2000;

interface ActionsProps {
    readonly busy: boolean;
    readonly confirmLabel: string;
    readonly destructive?: boolean;
    readonly onCancel: () => void;
}

function Actions({ busy, confirmLabel, destructive = false, onCancel }: ActionsProps): ReactNode {
    const t = useT();
    return (
        <div className="flex flex-wrap justify-end gap-2">
            <Button type="button" variant="outline" onClick={onCancel}>
                {t('common.cancel')}
            </Button>
            {/* aria-disabled, not disabled: the focused button must keep focus inside the dialog. */}
            <Button
                type="submit"
                variant={destructive ? 'destructive' : 'default'}
                aria-disabled={busy}
            >
                {busy ? t('common.saving') : confirmLabel}
            </Button>
        </div>
    );
}

function Problem({ problem }: { readonly problem: MessageKey | null }): ReactNode {
    const t = useT();
    return problem === null ? null : (
        <Alert variant="destructive" role="alert">
            {t(problem)}
        </Alert>
    );
}

export interface ApproveFormProps {
    readonly busy: boolean;
    readonly problem: MessageKey | null;
    readonly onConfirm: () => void;
    readonly onCancel: () => void;
}

export function ApproveForm({ busy, problem, onConfirm, onCancel }: ApproveFormProps): ReactNode {
    const t = useT();
    const submit = (event: SubmitEvent<HTMLFormElement>): void => {
        event.preventDefault();
        if (!busy) {
            onConfirm();
        }
    };
    return (
        <form className="flex flex-col gap-4" onSubmit={submit}>
            <Problem problem={problem} />
            <Actions busy={busy} confirmLabel={t('decision.approve')} onCancel={onCancel} />
        </form>
    );
}

export interface RejectFormProps {
    readonly busy: boolean;
    readonly problem: MessageKey | null;
    /** A server error on `/comment`. */
    readonly commentError: MessageKey | null;
    /** The comment changed: a server error on it no longer applies. */
    readonly onEdit: () => void;
    readonly onReject: (comment: string) => void;
    readonly onCancel: () => void;
}

export function RejectForm({
    busy,
    problem,
    commentError,
    onEdit,
    onReject,
    onCancel,
}: RejectFormProps): ReactNode {
    const t = useT();
    const id = useId();
    const field = useRef<HTMLTextAreaElement>(null);
    const [comment, setComment] = useState('');
    const [clientError, setClientError] = useState<MessageKey | null>(null);
    const message = clientError ?? commentError;

    // A rejected comment from the server: take the user to the field (the message is next to it).
    useEffect(() => {
        if (commentError !== null) {
            field.current?.focus();
        }
    }, [commentError]);

    const submit = (event: SubmitEvent<HTMLFormElement>): void => {
        event.preventDefault();
        if (busy) {
            return;
        }
        const trimmed = comment.trim();
        if (trimmed === '') {
            setClientError('decision.commentRequired');
            field.current?.focus();
            return;
        }
        onReject(trimmed);
    };

    return (
        <form className="flex flex-col gap-4" onSubmit={submit} noValidate>
            <Problem problem={problem} />
            <div className="flex flex-col gap-2">
                <Label htmlFor={`${id}-comment`}>{t('decision.comment')}</Label>
                <Textarea
                    ref={field}
                    id={`${id}-comment`}
                    required
                    maxLength={COMMENT_MAX_LENGTH}
                    value={comment}
                    aria-invalid={message !== null}
                    aria-describedby={message === null ? undefined : `${id}-error`}
                    onChange={(event) => {
                        setComment(event.target.value);
                        setClientError(null);
                        onEdit();
                    }}
                />
                {message === null ? null : (
                    <p id={`${id}-error`} className="font-semibold text-destructive">
                        {t(message)}
                    </p>
                )}
            </div>
            <Actions
                busy={busy}
                confirmLabel={t('decision.reject')}
                destructive
                onCancel={onCancel}
            />
        </form>
    );
}
