import { useNavigate } from '@tanstack/react-router';
import { Building2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '../components/ui/button.tsx';
import { useSession } from '../hooks/useSession.ts';
import { useSwitchOrganization } from '../hooks/useSwitchOrganization.ts';
import { useT } from '../i18n/I18nProvider.tsx';

export function OrgPickerScreen(): ReactNode {
    const t = useT();
    const { me } = useSession();
    const navigate = useNavigate();
    const switchOrganization = useSwitchOrganization();

    return (
        <section className="flex flex-col gap-4">
            <h1 className="text-2xl font-bold">{t('org.title')}</h1>
            {/* A deactivated membership can leave none (ADR 0035); Settings still offers logout. */}
            <p>{me.organizations.length === 0 ? t('account.noOrganizations') : t('org.hint')}</p>
            <ul className="flex flex-col gap-2">
                {me.organizations.map((organization) => (
                    <li key={organization.id}>
                        <Button
                            variant="outline"
                            size="lg"
                            className="w-full justify-start"
                            onClick={() => {
                                switchOrganization(organization.id);
                                void navigate({ to: '/' });
                            }}
                        >
                            <Building2 aria-hidden="true" />
                            {organization.name}
                        </Button>
                    </li>
                ))}
            </ul>
        </section>
    );
}
