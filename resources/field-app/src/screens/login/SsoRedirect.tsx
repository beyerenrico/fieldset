/**
 * Shown while the browser leaves for the organization's identity provider, and while the sign-in is
 * completed after its return (`?sso=ok`). The visible "Continue with …" link is a fallback when the
 * redirect does not start; "Use a different email" cancels a redirect that has not left yet.
 */
import { LoaderCircle } from 'lucide-react';
import { useLayoutEffect, useRef, type ReactNode } from 'react';
import { Button } from '../../components/ui/button.tsx';
import { useT, type Translate } from '../../i18n/I18nProvider.tsx';

/** "Continue with SSO Demo GmbH", or the generic central login when the answer names no organization. */
export function ssoContinueLabel(t: Translate, organization: string): string {
    return organization === ''
        ? t('auth.ssoContinueGeneric')
        : t('auth.ssoContinue', { organization });
}

function Progress({ title, text }: { readonly title: string; readonly text: string }): ReactNode {
    const heading = useRef<HTMLHeadingElement>(null);
    useLayoutEffect(() => {
        heading.current?.focus();
    }, []);
    return (
        <section role="status" aria-live="polite" className="flex flex-col gap-2">
            <h2
                ref={heading}
                tabIndex={-1}
                className="flex items-center gap-2 text-xl font-bold outline-none"
            >
                <LoaderCircle aria-hidden="true" className="size-6 shrink-0 animate-spin" />
                {title}
            </h2>
            <p>{text}</p>
        </section>
    );
}

export interface SsoRedirectProps {
    readonly organization: string;
    readonly redirectUrl: string;
    /** Back to the email step: cancels the redirect, or after returning with the browser's back button. */
    readonly onChangeEmail: () => void;
}

export function SsoRedirect({
    organization,
    redirectUrl,
    onChangeEmail,
}: SsoRedirectProps): ReactNode {
    const t = useT();
    const title = ssoContinueLabel(t, organization);
    return (
        <div className="flex flex-col gap-4">
            <Progress title={title} text={t('auth.ssoRedirecting')} />
            <Button asChild size="lg">
                <a href={redirectUrl}>{title}</a>
            </Button>
            <Button type="button" variant="link" onClick={onChangeEmail}>
                {t('auth.changeEmail')}
            </Button>
        </div>
    );
}

export function SsoCompleting(): ReactNode {
    const t = useT();
    return <Progress title={t('auth.ssoCompleting')} text={t('common.loading')} />;
}
