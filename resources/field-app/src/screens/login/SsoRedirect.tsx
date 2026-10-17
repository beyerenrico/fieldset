/**
 * Shown while the browser leaves for the organization's identity provider, and while the sign-in is
 * completed after its return (`?sso=ok`). The link is a fallback when the redirect does not start.
 */
import { LoaderCircle } from 'lucide-react';
import { useLayoutEffect, useRef, type ReactNode } from 'react';
import { Button } from '../../components/ui/button.tsx';
import { useT } from '../../i18n/I18nProvider.tsx';

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
    /** Back to the email step (e.g. after returning from the IdP with the browser's back button). */
    readonly onChangeEmail: () => void;
}

export function SsoRedirect({
    organization,
    redirectUrl,
    onChangeEmail,
}: SsoRedirectProps): ReactNode {
    const t = useT();
    const title =
        organization === ''
            ? t('auth.ssoContinueGeneric')
            : t('auth.ssoContinue', { organization });
    return (
        <div className="flex flex-col gap-4">
            <Progress title={title} text={t('auth.ssoRedirecting')} />
            <Button asChild variant="outline" size="lg">
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
