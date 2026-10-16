import { useQuery } from '@tanstack/react-query';
import { Search, Star } from 'lucide-react';
import { useId, useMemo, useState, type ReactNode } from 'react';
import { ErrorState, Loading } from '../components/RouteError.tsx';
import { Button } from '../components/ui/button.tsx';
import { Card } from '../components/ui/card.tsx';
import { Input } from '../components/ui/input.tsx';
import { Label } from '../components/ui/label.tsx';
import { formsQueryOptions } from '../hooks/queries.ts';
import { useOrganizationId } from '../hooks/useSession.ts';
import { useShortcuts, useStartForm } from '../hooks/useShortcuts.ts';
import { useT } from '../i18n/I18nProvider.tsx';
import type { FormSummary } from '../lib/api/types.ts';
import { cn } from '../lib/cn.ts';

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

function FormRow({
    form,
    favorite,
    onToggle,
}: {
    readonly form: FormSummary;
    readonly favorite: boolean;
    readonly onToggle: () => void;
}): ReactNode {
    const t = useT();
    const start = useStartForm();
    return (
        <Card className="flex items-center gap-2 p-2">
            <Button
                variant="ghost"
                className="min-w-0 flex-1 justify-start text-left"
                aria-label={t('catalog.start', { name: form.name })}
                onClick={() => {
                    start(form);
                }}
            >
                <span className="flex min-w-0 flex-col">
                    <span className="truncate">{form.name}</span>
                    {form.category === null ? null : (
                        <span className="text-sm font-normal text-muted-foreground">
                            {form.category}
                        </span>
                    )}
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

export function CatalogScreen(): ReactNode {
    const t = useT();
    const id = useId();
    const org = useOrganizationId();
    const forms = useQuery(formsQueryOptions(org));
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
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                        }}
                    />
                </div>
            </div>
            {categories.length > 1 ? (
                <fieldset className="flex flex-wrap gap-2">
                    <legend className="mb-2 font-semibold">{t('catalog.categories')}</legend>
                    {[ALL, ...categories].map((value) => (
                        <Button
                            key={value}
                            variant={category === value ? 'default' : 'outline'}
                            aria-pressed={category === value}
                            onClick={() => {
                                setCategory(value);
                            }}
                        >
                            {value === ALL
                                ? t('catalog.allCategories')
                                : value === ''
                                  ? t('catalog.uncategorized')
                                  : value}
                        </Button>
                    ))}
                </fieldset>
            ) : null}
            {forms.isPending ? <Loading /> : null}
            {forms.isError ? (
                <ErrorState error={forms.error} onRetry={() => void forms.refetch()} />
            ) : null}
            {forms.isSuccess && visible.length === 0 ? (
                <p className="text-muted-foreground">{t('catalog.empty')}</p>
            ) : null}
            <ul className="flex flex-col gap-2" aria-live="polite">
                {visible.map((form) => (
                    <li key={form.id}>
                        <FormRow
                            form={form}
                            favorite={favorites.includes(form.id)}
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
