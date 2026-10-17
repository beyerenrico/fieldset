import { useId, type ReactNode } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card.tsx';
import { Label } from '../components/ui/label.tsx';
import { useSession } from '../hooks/useSession.ts';
import { useSwitchOrganization } from '../hooks/useSwitchOrganization.ts';
import { useI18n, type Language } from '../i18n/I18nProvider.tsx';
import { LogoutCard } from './settings/LogoutCard.tsx';
import { NotificationPreferences } from './settings/NotificationPreferences.tsx';
import { StorageCard } from './settings/StorageCard.tsx';
import { SyncCard } from './settings/SyncCard.tsx';

const SELECT =
    'min-h-11 w-full rounded-md border-2 border-input bg-background px-3 text-base focus-visible:outline-3 focus-visible:outline-ring';

export function SettingsScreen(): ReactNode {
    const { t, language, setLanguage, preferences, setLargeText } = useI18n();
    const { me, organization } = useSession();
    const switchOrganization = useSwitchOrganization();
    const id = useId();

    return (
        <div className="flex flex-col gap-4">
            <h1 className="text-2xl font-bold">{t('settings.title')}</h1>
            <SyncCard />
            <StorageCard />
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
