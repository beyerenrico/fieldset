import { useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { Building2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '../components/ui/button.tsx';
import { useSession } from '../hooks/useSession.ts';
import { useT } from '../i18n/I18nProvider.tsx';
import { setOrganizationId } from '../lib/session/org-store.ts';

export function OrgPickerScreen(): ReactNode {
    const t = useT();
    const { me } = useSession();
    const navigate = useNavigate();
    const queryClient = useQueryClient();

    return (
        <section className="flex flex-col gap-4">
            <h1 className="text-2xl font-bold">{t('org.title')}</h1>
            <p>{t('org.hint')}</p>
            <ul className="flex flex-col gap-2">
                {me.organizations.map((organization) => (
                    <li key={organization.id}>
                        <Button
                            variant="outline"
                            size="lg"
                            className="w-full justify-start"
                            onClick={() => {
                                setOrganizationId(organization.id);
                                queryClient.removeQueries({
                                    predicate: (query) => query.queryKey[0] !== 'me',
                                });
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
