/**
 * After sign-in: this device still holds unsynced submissions of another user (QUESTIONS #15). They
 * stay hidden and are never sent under the new session; the new user may delete them after a second
 * confirmation. Focus follows the heading of each step.
 */
import { UserX } from 'lucide-react';
import { useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import { deleteForeignRows } from '../../lib/session/unsynced.ts';

export interface ForeignDataPromptProps {
    readonly userId: number;
    /** Submissions of other users with unsynced data. */
    readonly count: number;
    readonly onDone: () => void;
}

type Step = 'ask' | 'confirm';

/** aria-disabled keeps focus on a busy button (a disabled one drops it to the page). */
const busyProps = (busy: boolean) => ({ 'aria-disabled': busy ? true : undefined });

interface StepButtonsProps {
    readonly confirming: boolean;
    readonly busy: boolean;
    /** Keep (first step) or back (confirmation). */
    readonly onSafe: () => void;
    /** Ask for confirmation (first step) or delete (confirmation). */
    readonly onDestructive: () => void;
}

/** The destructive action sits apart from the safe one (no slip of the thumb). */
function StepButtons({ confirming, busy, onSafe, onDestructive }: StepButtonsProps): ReactNode {
    const t = useT();
    const unlessBusy = (action: () => void) => () => {
        if (!busy) {
            action();
        }
    };
    return (
        <div className="flex flex-col gap-8">
            <Button variant="outline" size="lg" {...busyProps(busy)} onClick={unlessBusy(onSafe)}>
                {t(confirming ? 'account.foreignBack' : 'account.foreignKeep')}
            </Button>
            <Button
                variant="destructive"
                size="lg"
                {...busyProps(busy)}
                onClick={unlessBusy(onDestructive)}
            >
                {t(confirming ? 'account.foreignConfirmDelete' : 'account.foreignDelete')}
            </Button>
        </div>
    );
}

/** Deletes the other users' rows; busy while it runs, failed when it did not work. */
function useForeignDelete(userId: number, onDone: () => void) {
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const remove = async (): Promise<void> => {
        setBusy(true);
        setFailed(false);
        try {
            await deleteForeignRows(userId);
            onDone();
        } catch {
            setFailed(true);
            setBusy(false);
        }
    };
    return { busy, failed, remove };
}

export function ForeignDataPrompt({ userId, count, onDone }: ForeignDataPromptProps): ReactNode {
    const t = useT();
    const [step, setStep] = useState<Step>('ask');
    const { busy, failed, remove } = useForeignDelete(userId, onDone);
    const heading = useRef<HTMLHeadingElement>(null);

    useLayoutEffect(() => {
        heading.current?.focus();
    }, [step]);

    const confirming = step === 'confirm';
    return (
        <section aria-labelledby="foreign-data-title" className="flex flex-col gap-4">
            <h2
                id="foreign-data-title"
                ref={heading}
                tabIndex={-1}
                className="flex items-center gap-2 text-xl font-bold outline-none"
            >
                <UserX aria-hidden="true" className="size-6" />
                {t(confirming ? 'account.foreignConfirmTitle' : 'account.foreignTitle')}
            </h2>
            <p>{t(confirming ? 'account.foreignConfirmText' : 'account.foreignText', { count })}</p>
            {failed ? (
                <Alert variant="destructive" role="alert">
                    {t('error.generic')}
                </Alert>
            ) : null}
            <StepButtons
                confirming={confirming}
                busy={busy}
                onSafe={
                    confirming
                        ? () => {
                              setStep('ask');
                          }
                        : onDone
                }
                onDestructive={
                    confirming
                        ? () => void remove()
                        : () => {
                              setStep('confirm');
                          }
                }
            />
        </section>
    );
}
