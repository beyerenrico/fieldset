/**
 * After sign-in: this device still holds unsynced items of another user (QUESTIONS #15). They stay
 * hidden and are never sent under the new session; the new user may delete them.
 */
import { UserX } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import { deleteForeignRows } from '../../lib/session/unsynced.ts';

export interface ForeignDataPromptProps {
    readonly userId: number;
    readonly count: number;
    readonly onDone: () => void;
}

export function ForeignDataPrompt({ userId, count, onDone }: ForeignDataPromptProps): ReactNode {
    const t = useT();
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

    return (
        <section aria-labelledby="foreign-data-title" className="flex flex-col gap-4">
            <h2 id="foreign-data-title" className="flex items-center gap-2 text-xl font-bold">
                <UserX aria-hidden="true" className="size-6" />
                {t('account.foreignTitle')}
            </h2>
            <p>{t('account.foreignText', { count })}</p>
            {failed ? (
                <Alert variant="destructive" role="alert">
                    {t('error.generic')}
                </Alert>
            ) : null}
            <div className="flex flex-col gap-2">
                <Button variant="outline" size="lg" disabled={busy} onClick={onDone}>
                    {t('account.foreignKeep')}
                </Button>
                <Button
                    variant="destructive"
                    size="lg"
                    disabled={busy}
                    onClick={() => void remove()}
                >
                    {t('account.foreignDelete')}
                </Button>
            </div>
        </section>
    );
}
