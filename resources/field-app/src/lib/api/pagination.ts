/** Pagination `meta` of list endpoints, read defensively (missing fields fall back). */
import type { PaginationMeta } from './types.ts';

const EMPTY_META: PaginationMeta = { current_page: 1, last_page: 1, total: 0 };

export function readNumber(
    meta: Readonly<Record<string, unknown>> | null,
    key: string,
    fallback: number,
): number {
    const value = meta?.[key];
    return typeof value === 'number' ? value : fallback;
}

export function toMeta(
    meta: Readonly<Record<string, unknown>> | null,
    count: number,
): PaginationMeta {
    return {
        current_page: readNumber(meta, 'current_page', EMPTY_META.current_page),
        last_page: readNumber(meta, 'last_page', EMPTY_META.last_page),
        total: readNumber(meta, 'total', count),
    };
}

/** The next page number, or undefined on the last page (for infinite queries). */
export function nextPage(meta: PaginationMeta): number | undefined {
    return meta.current_page < meta.last_page ? meta.current_page + 1 : undefined;
}
