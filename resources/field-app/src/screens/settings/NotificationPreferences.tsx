/** Settings card (§8.9): which events reach me in the app and by email. */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useId, useState, type ReactNode, type SubmitEvent } from 'react';
import { ErrorState, Loading } from '../../components/RouteError.tsx';
import { Alert } from '../../components/ui/alert.tsx';
import { Button } from '../../components/ui/button.tsx';
import { Card, CardContent, CardHeader, CardTitle } from '../../components/ui/card.tsx';
import { notificationPreferencesQueryOptions } from '../../hooks/queries.ts';
import { useT, type MessageKey, type Translate } from '../../i18n/I18nProvider.tsx';
import { NetworkError } from '../../lib/api/errors.ts';
import {
    NOTIFICATION_CHANNELS,
    type NotificationChannel,
    type NotificationPreference,
} from '../../lib/api/types.ts';
import { saveNotificationPreferences } from '../../lib/api/workflow.ts';
import { reportAuthError } from '../../lib/session/auth-events.ts';

const EVENT_LABELS: Readonly<Record<string, MessageKey | undefined>> = {
    review_assigned: 'event.review_assigned',
    changes_requested: 'event.changes_requested',
    submission_approved: 'event.submission_approved',
    workflow_failed: 'event.workflow_failed',
    form_available: 'event.form_available',
    workflow_notice: 'event.workflow_notice',
    export_ready: 'event.export_ready',
    sso_misconfigured: 'event.sso_misconfigured',
};

const CHANNEL_LABELS: Readonly<Record<NotificationChannel, MessageKey>> = {
    database: 'prefs.database',
    mail: 'prefs.mail',
};

/** Localized event name; events added on the server later fall back to a readable key. */
export function eventLabel(t: Translate, event: string): string {
    const key = EVENT_LABELS[event];
    return key === undefined ? event.replaceAll('_', ' ') : t(key);
}

function withChannel(
    events: readonly NotificationPreference[],
    event: string,
    channel: NotificationChannel,
    enabled: boolean,
): NotificationPreference[] {
    return events.map((item) =>
        item.event === event
            ? { ...item, channels: { ...item.channels, [channel]: enabled } }
            : item,
    );
}

interface FormProps {
    readonly initial: readonly NotificationPreference[];
}

function PreferencesForm({ initial }: FormProps): ReactNode {
    const t = useT();
    const queryClient = useQueryClient();
    const [events, setEvents] = useState(initial);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [problem, setProblem] = useState<MessageKey | null>(null);

    const toggle = (event: string, channel: NotificationChannel, enabled: boolean): void => {
        setEvents((current) => withChannel(current, event, channel, enabled));
        setSaved(false);
    };

    const save = async (submitEvent: SubmitEvent<HTMLFormElement>): Promise<void> => {
        submitEvent.preventDefault();
        if (saving) {
            return;
        }
        setSaving(true);
        setSaved(false);
        setProblem(null);
        try {
            const stored = await saveNotificationPreferences(events);
            queryClient.setQueryData(notificationPreferencesQueryOptions().queryKey, stored);
            if (stored.length > 0) {
                setEvents(stored);
            }
            setSaved(true);
        } catch (error) {
            if (!reportAuthError(error)) {
                setProblem(error instanceof NetworkError ? 'prefs.offline' : 'prefs.error');
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <form className="flex flex-col gap-3" onSubmit={(event) => void save(event)}>
            <div className="flex flex-col divide-y-2 divide-border">
                {events.map((item) => (
                    <fieldset key={item.event} className="py-2">
                        <legend className="pt-2 font-semibold">{eventLabel(t, item.event)}</legend>
                        <div className="flex flex-wrap gap-x-6">
                            {NOTIFICATION_CHANNELS.map((channel) => (
                                <label
                                    key={channel}
                                    className="flex min-h-11 cursor-pointer items-center gap-3"
                                >
                                    <input
                                        type="checkbox"
                                        className="size-5 accent-primary"
                                        checked={item.channels[channel]}
                                        onChange={(change) => {
                                            toggle(item.event, channel, change.target.checked);
                                        }}
                                    />
                                    {t(CHANNEL_LABELS[channel])}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                ))}
            </div>
            <div role="status">
                {saved ? <p className="font-semibold">{t('prefs.saved')}</p> : null}
            </div>
            {problem === null ? null : (
                <Alert variant="destructive" role="alert">
                    {t(problem)}
                </Alert>
            )}
            {/* aria-disabled, not disabled: focus stays on the button while saving. */}
            <Button type="submit" className="self-start" aria-disabled={saving}>
                {saving ? t('common.saving') : t('common.save')}
            </Button>
        </form>
    );
}

export function NotificationPreferences(): ReactNode {
    const t = useT();
    const titleId = useId();
    const query = useQuery(notificationPreferencesQueryOptions());
    return (
        <Card role="region" aria-labelledby={titleId}>
            <CardHeader>
                <CardTitle id={titleId}>{t('prefs.title')}</CardTitle>
                <p className="text-sm text-muted-foreground">{t('prefs.hint')}</p>
            </CardHeader>
            <CardContent>
                {query.isPending ? <Loading /> : null}
                {query.isError ? (
                    <ErrorState error={query.error} onRetry={() => void query.refetch()} />
                ) : null}
                {query.data === undefined ? null : query.data.length === 0 ? (
                    <p className="text-muted-foreground">{t('prefs.empty')}</p>
                ) : (
                    <PreferencesForm initial={query.data} />
                )}
            </CardContent>
        </Card>
    );
}
