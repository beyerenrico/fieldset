import type { FormDefinition, JsonObject } from '@form-schema-app/form-schema-js';
import { useEffect, useId, useMemo, useRef, type ReactNode } from 'react';
import { useT } from '../i18n/I18nProvider.tsx';
import { diffDrafts, summarize, type SummaryTexts } from '../lib/autosave/conflict-diff.ts';
import { Button } from './ui/button.tsx';

export interface ConflictPanelProps {
    readonly definition: FormDefinition;
    readonly mine: JsonObject;
    readonly server: JsonObject;
    readonly onChoose: (choice: 'mine' | 'server') => void;
}

/** "Server version vs mine" (§9.2 conflicts): both versions side by side, the user decides. */
export function ConflictPanel({
    definition,
    mine,
    server,
    onChoose,
}: ConflictPanelProps): ReactNode {
    const t = useT();
    const titleId = useId();
    const heading = useRef<HTMLHeadingElement>(null);
    const diffs = useMemo(() => diffDrafts(definition, mine, server), [definition, mine, server]);
    const texts: SummaryTexts = {
        empty: t('common.empty'),
        yes: t('common.yes'),
        no: t('common.no'),
        rows: (count) => t('conflict.rows', { count }),
    };

    useEffect(() => {
        heading.current?.focus();
    }, []);

    return (
        <section
            role="alertdialog"
            aria-labelledby={titleId}
            className="my-4 rounded-lg border-2 border-destructive bg-card p-4"
        >
            <h2 id={titleId} ref={heading} tabIndex={-1} className="text-lg font-bold outline-none">
                {t('conflict.title')}
            </h2>
            <p className="mt-1">{t('conflict.description')}</p>
            {diffs.length === 0 ? (
                <p className="mt-3 text-muted-foreground">{t('conflict.noDiff')}</p>
            ) : (
                <div className="mt-3 overflow-x-auto">
                    <table className="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr className="border-b-2 border-border">
                                <th scope="col" className="py-2 pr-2">
                                    {t('conflict.field')}
                                </th>
                                <th scope="col" className="py-2 pr-2">
                                    {t('conflict.mine')}
                                </th>
                                <th scope="col" className="py-2">
                                    {t('conflict.server')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {diffs.map((diff) => (
                                <tr key={diff.key} className="border-b border-border align-top">
                                    <th scope="row" className="py-2 pr-2 font-semibold">
                                        {diff.label}
                                    </th>
                                    <td className="py-2 pr-2">{summarize(diff.mine, texts)}</td>
                                    <td className="py-2">{summarize(diff.server, texts)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <div className="mt-4 flex flex-wrap gap-2">
                <Button
                    onClick={() => {
                        onChoose('mine');
                    }}
                >
                    {t('conflict.keepMine')}
                </Button>
                <Button
                    variant="outline"
                    onClick={() => {
                        onChoose('server');
                    }}
                >
                    {t('conflict.useServer')}
                </Button>
            </div>
        </section>
    );
}
