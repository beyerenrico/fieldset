import { useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { LogOut } from 'lucide-react';
import { useId, useState, type ReactNode } from 'react';
import { Alert } from '../components/ui/alert.tsx';
import { Button } from '../components/ui/button.tsx';
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card.tsx';
import { Label } from '../components/ui/label.tsx';
import { useSession } from '../hooks/useSession.ts';
import { useSwitchOrganization } from '../hooks/useSwitchOrganization.ts';
import { useI18n, type Language } from '../i18n/I18nProvider.tsx';
import { logout } from '../lib/api/endpoints.ts';
import { rememberNotice } from '../lib/session/auth-events.ts';
import { clearLocalData } from '../lib/session/local-data.ts';
import { NotificationPreferences } from './settings/NotificationPreferences.tsx';

const SELECT =
    'min-h-11 w-full rounded-md border-2 border-input bg-background px-3 text-base focus-visible:outline-3 focus-visible:outline-ring';

function LogoutCard(): ReactNode {
    const { t } = useI18n();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);

    const signOut = async (): Promise<void> => {
        setBusy(true);
        try {
            await logout();
        } catch {
            // Offline or already signed out: local data is cleared anyway.
        }
        await clearLocalData({ keepDeviceId: true });
        queryClient.clear();
        await navigate({ to: '/login', search: { notice: rememberNotice('logout') ?? 'logout' } });
    };

    if (!confirming) {
        return (
            <Button
                variant="outline"
                size="lg"
                onClick={() => {
                    setConfirming(true);
                }}
            >
                <LogOut aria-hidden="true" />
                {t('settings.logout')}
            </Button>
        );
    }
    return (
        <Alert variant="destructive" role="alertdialog" aria-label={t('settings.logout')}>
            <p>{t('settings.logoutConfirm')}</p>
            <div className="mt-3 flex flex-wrap gap-2">
                <Button variant="destructive" disabled={busy} onClick={() => void signOut()}>
                    {t('settings.logoutConfirmAction')}
                </Button>
                <Button
                    variant="outline"
                    disabled={busy}
                    onClick={() => {
                        setConfirming(false);
                    }}
                >
                    {t('common.cancel')}
                </Button>
            </div>
        </Alert>
    );
}

export function SettingsScreen(): ReactNode {
    const { t, language, setLanguage, preferences, setLargeText } = useI18n();
    const { me, organization } = useSession();
    const switchOrganization = useSwitchOrganization();
    const id = useId();

    return (
        <div className="flex flex-col gap-4">
            <h1 className="text-2xl font-bold">{t('settings.title')}</h1>
            <Card>
                <CardHeader>
                    <CardTitle>{t('settings.language')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <fieldset className="flex flex-col gap-1">
                        <legend className="sr-only">{t('settings.language')}</legend>
                        {(['de', 'en'] as const satisfies readonly Language[]).map((value) => (
                            <label
                                key={value}
                                className="flex min-h-11 cursor-pointer items-center gap-3"
                            >
                                <input
                                    type="radio"
                                    name={`${id}-language`}
                                    className="size-5 accent-primary"
                                    checked={language === value}
                                    onChange={() => {
                                        setLanguage(value);
                                    }}
                                />
                                {value === 'de'
                                    ? t('settings.languageDe')
                                    : t('settings.languageEn')}
                            </label>
                        ))}
                    </fieldset>
                </CardContent>
            </Card>
            <Card>
                <CardContent className="pt-4">
                    <label className="flex min-h-11 cursor-pointer items-center justify-between gap-3">
                        <span className="flex flex-col">
                            <span className="font-semibold">{t('settings.largeText')}</span>
                            <span className="text-sm text-muted-foreground">
                                {t('settings.largeTextHint')}
                            </span>
                        </span>
                        <input
                            type="checkbox"
                            role="switch"
                            className="size-6 accent-primary"
                            checked={preferences.largeText}
                            onChange={(event) => {
                                setLargeText(event.target.checked);
                            }}
                        />
                    </label>
                </CardContent>
            </Card>
            <NotificationPreferences />
            {me.organizations.length > 1 ? (
                <Card>
                    <CardContent className="flex flex-col gap-2 pt-4">
                        <Label htmlFor={`${id}-org`}>{t('settings.organization')}</Label>
                        <select
                            id={`${id}-org`}
                            className={SELECT}
                            value={organization?.id ?? ''}
                            onChange={(event) => {
                                switchOrganization(Number(event.target.value));
                            }}
                        >
                            {me.organizations.map((candidate) => (
                                <option key={candidate.id} value={candidate.id}>
                                    {candidate.name}
                                </option>
                            ))}
                        </select>
                    </CardContent>
                </Card>
            ) : null}
            <p className="text-muted-foreground">
                {t('settings.account', { email: me.user.email })}
            </p>
            <LogoutCard />
            <p className="text-sm text-muted-foreground">
                {t('settings.version', { version: __APP_VERSION__ })}
            </p>
        </div>
    );
}
