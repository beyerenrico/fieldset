/** RFC 6901 JSON Pointer helpers. */

export type PathSegment = string | number;

export function escapeToken(token: string): string {
    return token.replace(/~/g, '~0').replace(/\//g, '~1');
}

export function toPointer(segments: readonly PathSegment[]): string {
    return segments.map((segment) => `/${escapeToken(String(segment))}`).join('');
}
