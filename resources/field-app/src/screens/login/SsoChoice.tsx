/**
 * Discovery found the organization's central login and the organization does not enforce it (ADR 0036
 * "Home-realm discovery"): continue there (the primary action) or sign in with a password.
 */
import { useId, useLayoutEffect, useRef, type ReactNode } from 'react';
import { Button } from '../../components/ui/button.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';
import { ssoContinueLabel } from './SsoRedirect.tsx';

export interface SsoChoiceProps {
    readonly email: string;
    readonly organization: string;
    readonly onContinue: () => void;
    readonly onPassword: () => void;
    readonly onChangeEmail: () => void;
}

export function SsoChoice({
    email,
    organization,
    onContinue,
    onPassword,
    onChangeEmail,
}: SsoChoiceProps): ReactNode {
    const t = useT();
    const id = useId();
    const heading = useRef<HTMLHeadingElement>(null);
    useLayoutEffect(() => {
        heading.current?.focus();
    }, []);
    return (
        <section aria-labelledby={`${id}-title`} className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h2
                    ref={heading}
                    id={`${id}-title`}
                    tabIndex={-1}
                    className="text-xl font-bold outline-none"
                >
                    {t('auth.ssoChoiceTitle')}
                </h2>
                <p className="break-all text-muted-foreground">{email}</p>
            </div>
            <Button type="button" size="lg" onClick={onContinue}>
                {ssoContinueLabel(t, organization)}
            </Button>
            <Button type="button" variant="outline" size="lg" onClick={onPassword}>
                {t('auth.usePassword')}
            </Button>
            <Button type="button" variant="link" onClick={onChangeEmail}>
                {t('auth.changeEmail')}
            </Button>
        </section>
    );
}
