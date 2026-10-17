import { useQuery } from '@tanstack/react-query';
import { CloudOff, Search, Star } from 'lucide-react';
import { useId, useMemo, useState, type ReactNode } from 'react';
import { ErrorState, Loading } from '../components/RouteError.tsx';
import { Alert } from '../components/ui/alert.tsx';
import { Button } from '../components/ui/button.tsx';
import { Card } from '../components/ui/card.tsx';
import { Input } from '../components/ui/input.tsx';
import { Label } from '../components/ui/label.tsx';
import { formsQueryOptions } from '../hooks/queries.ts';
import { useOnline } from '../hooks/useOnline.ts';
import { useOrganizationId } from '../hooks/useSession.ts';
import { useShortcuts, useStartForm } from '../hooks/useShortcuts.ts';
import { useT } from '../i18n/I18nProvider.tsx';
import type { FormSummary } from '../lib/api/types.ts';
import { listCachedVersionIds, NotCachedError } from '../lib/catalog/catalog.ts';
import { cn } from '../lib/cn.ts';
import { useLiveQuery } from '../lib/db/live.ts';

/** Filter sentinel; uncategorized forms use the empty string. */
export const ALL = '*';

export function filterForms(
    forms: readonly FormSummary[],
    query: string,
    category: string,
): FormSummary[] {
    const needle = query.trim().toLocaleLowerCase();
    return forms.filter(
        (form) =>
            (category === ALL || (form.category ?? '') === category) &&
            (needle === '' ||
                form.name.toLocaleLowerCase().includes(needle) ||
                form.key.includes(needle)),
    );
}

interface FormRowProps {
    readonly form: FormSummary;
    readonly favorite: boolean;
    readonly onToggle: () => void;
    /** Offline and its version is not on this device: starting it cannot work. */
    readonly unavailable: boolean;
}

function FormRow({ form, favorite, onToggle, unavailable }: FormRowProps): ReactNode {
    const t = useT();
    const start = useStartForm();
    return (
        <Card className="flex items-center gap-2 p-2">
            <Button
                variant="ghost"
                className="min-w-0 flex-1 justify-start text-left"
                aria-label={t('catalog.start', { name: form.name })}
                aria-disabled={unavailable ? true : undefined}
                onClick={() => {
                    if (!unavailable) {
                        start(form);
                    }
                }}
            >
                <span className="flex min-w-0 flex-col">
                    <span className="truncate">{form.name}</span>
                    {form.category === null ? null : (
                        <span className="text-sm font-normal text-muted-foreground">
                            {form.category}
                        </span>
                    )}
                    {unavailable ? (
                        <span className="inline-flex items-center gap-1 text-sm font-normal">
                            <CloudOff aria-hidden="true" className="size-4" />
                            {t('catalog.formNotCached')}
                        </span>
                    ) : null}
                </span>
            </Button>
            <Button
                variant="ghost"
                size="icon"
                aria-pressed={favorite}
                aria-label={t(favorite ? 'catalog.unfavorite' : 'catalog.favorite', {
                    name: form.name,
                })}
                onClick={onToggle}
            >
                <Star aria-hidden="true" className={cn(favorite && 'fill-current text-warning')} />
            </Button>
        </Card>
    );
}

function CategoryFilter({
    categories,
    value,
    onChange,
}: {
    readonly categories: readonly string[];
    readonly value: string;
    readonly onChange: (category: string) => void;
}): ReactNode {
    const t = useT();
    if (categories.length < 2) {
        return null;
    }
    return (
        <fieldset className="flex flex-wrap gap-2">
            <legend className="mb-2 font-semibold">{t('catalog.categories')}</legend>
            {[ALL, ...categories].map((category) => (
                <Button
                    key={category}
                    variant={value === category ? 'default' : 'outline'}
                    aria-pressed={value === category}
                    onClick={() => {
                        onChange(category);
                    }}
                >
                    {category === ALL
                        ? t('catalog.allCategories')
                        : category === ''
                          ? t('catalog.uncategorized')
                          : category}
                </Button>
            ))}
        </fieldset>
    );
}

/** Loading, "not available offline", other errors and the offline notice above the list. */
function CatalogStatus({
    forms,
}: {
    readonly forms: ReturnType<typeof useCatalogForms>;
}): ReactNode {
    const t = useT();
    const online = useOnline();
    if (forms.isPending) {
        return <Loading />;
    }
    if (forms.error instanceof NotCachedError) {
        return (
            <Alert variant="warning" role="status">
                <p>{t('catalog.notCached')}</p>
                <Button variant="outline" className="mt-2" onClick={() => void forms.refetch()}>
                    {t('common.retry')}
                </Button>
            </Alert>
        );
    }
    if (forms.isError) {
        return <ErrorState error={forms.error} onRetry={() => void forms.refetch()} />;
    }
    return online ? null : (
        <p role="status" className="text-muted-foreground">
            {t('catalog.offlineCached')}
        </p>
    );
}

function useCatalogForms() {
    return useQuery(formsQueryOptions(useOrganizationId()));
}

function SearchField({
    value,
    onChange,
}: {
    readonly value: string;
    readonly onChange: (value: string) => void;
}): ReactNode {
    const t = useT();
    const id = useId();
    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor={`${id}-search`}>{t('catalog.search')}</Label>
            <div className="relative">
                <Search
                    aria-hidden="true"
                    className="pointer-events-none absolute left-3 top-3 size-5 text-muted-foreground"
                />
                <Input
                    id={`${id}-search`}
                    type="search"
                    className="pl-10"
                    value={value}
                    onChange={(event) => {
                        onChange(event.target.value);
                    }}
                />
            </div>
        </div>
    );
}

/** Forms whose version is not on this device, while offline (starting them cannot work). */
function useOfflineUnavailable(): (form: FormSummary) => boolean {
    const org = useOrganizationId();
    const online = useOnline();
    const cachedVersions = useLiveQuery(
        () => (org === null ? Promise.resolve([]) : listCachedVersionIds(org)),
        [org],
    );
    return (form) => !online && cachedVersions?.includes(form.version.id) === false;
}

export function CatalogScreen(): ReactNode {
    const t = useT();
    const forms = useCatalogForms();
    const isUnavailable = useOfflineUnavailable();
    const { favorites, toggle } = useShortcuts();
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState(ALL);
    const categories = useMemo(
        () => [...new Set((forms.data ?? []).map((form) => form.category ?? ''))].sort(),
        [forms.data],
    );
    const visible = useMemo(
        () => filterForms(forms.data ?? [], query, category),
        [forms.data, query, category],
    );

    return (
        <div className="flex flex-col gap-4">
            <h1 className="text-2xl font-bold">{t('catalog.title')}</h1>
            <SearchField value={query} onChange={setQuery} />
            <CategoryFilter categories={categories} value={category} onChange={setCategory} />
            <CatalogStatus forms={forms} />
            {forms.isSuccess && visible.length === 0 ? (
                <p className="text-muted-foreground">{t('catalog.empty')}</p>
            ) : null}
            <ul className="flex flex-col gap-2" aria-live="polite">
                {visible.map((form) => (
                    <li key={form.id}>
                        <FormRow
                            form={form}
                            favorite={favorites.includes(form.id)}
                            unavailable={isUnavailable(form)}
                            onToggle={() => {
                                toggle(form.id);
                            }}
                        />
                    </li>
                ))}
            </ul>
        </div>
    );
}
