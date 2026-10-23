<?php

declare(strict_types=1);

namespace Tests\Support\Docs;

use RuntimeException;

/**
 * What the guides name in code (ADR 0042: every command, environment variable and path in a guide must exist in the
 * code): `fieldset` invocations with their flags, install.sh flags, `php artisan` commands, environment variables and
 * relative links. Only code is scanned: fenced blocks and inline code spans. One check reads the prose instead: raw
 * HTML tags and `<placeholder>` words outside code (ADR 0094).
 */
final class GuideReferences
{
    /** A segment of one command line: up to a pipe, `;`, `&&` or the end of the line. */
    private const string SEGMENT = '[^|;&\n`]*';

    /** The CLI's name before the rename to `fieldset` (2.1.0); none of its commands exists any more. */
    private const string FORMER_CLI = 'fsa';

    /**
     * An HTML start or end tag, roughly as CommonMark reads one: `<name>`, `<name attribute …>`, `</name>`. Autolinks
     * are no tags: a `:` (`<https://…>`) or `@` (`<a@example.com>`) follows their first word.
     */
    private const string RAW_TAG = '~</?[A-Za-z][A-Za-z0-9-]*(?:\s[^<>\n]*)?/?>~';

    /** An inline code span of any backtick run length; it may wrap lines but never crosses a blank line. */
    private const string CODE_SPAN = '/(?<!`)(`+)(?!`)((?:(?!\n\s*\n).)+?)(?<!`)\1(?!`)/s';

    /** @return list<string> fenced block bodies and inline code spans */
    public static function code(string $markdown): array
    {
        $code = array_map(static fn (array $block): string => $block['body'], GuideBlocks::fenced($markdown));
        $prose = (string) preg_replace('/^\s*```.*?^\s*```\s*$/ms', '', $markdown);
        preg_match_all('/`([^`\n]+)`/', $prose, $spans);

        return [...$code, ...$spans[1]];
    }

    /**
     * `fieldset <command> … --flag` invocations (the shim, a PATH link or `<dir>/fieldset`).
     *
     * @return list<array{command: string, flags: list<string>, text: string}>
     */
    public static function fieldsetCalls(string $code): array
    {
        $code = (string) preg_replace('/\\\\\n\s*/', ' ', $code);
        preg_match_all('~(?:^|[\s/(])fieldset ([a-z][a-z-]*)('.self::SEGMENT.')~m', $code, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $m): array => ['command' => $m[1], 'flags' => self::flags($m[2]), 'text' => trim('fieldset '.$m[1].$m[2])], $matches);
    }

    /**
     * Invocations of the CLI's name before 2.1.0: `fsa <command>` and its artisan names `fsa:<command>`. The bare
     * name (`fsa`) and words that only start with it (`fsa-test-…`) are not invocations.
     *
     * @return list<string>
     */
    public static function formerCliCalls(string $code): array
    {
        preg_match_all('~(?:^|[\s/(])('.self::FORMER_CLI.'[ :][a-z][a-z-]*)~m', $code, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * install.sh invocations: the one-liner (`… install.sh | sh -s -- …`) and `install.sh --…` written out.
     *
     * @return list<array{flags: list<string>, text: string}>
     */
    public static function installCalls(string $code): array
    {
        $code = (string) preg_replace('/\\\\\n\s*/', ' ', $code);
        preg_match_all('~install\.sh(?:\s*\|\s*(?:sudo\s+)?sh -s --)?((?:\s+--?[a-z]'.self::SEGMENT.')?)~', $code, $matches, PREG_SET_ORDER);

        return array_values(array_filter(array_map(static fn (array $m): array => ['flags' => self::flags($m[1]), 'text' => trim('install.sh'.$m[1])], $matches), static fn (array $call): bool => $call['flags'] !== []));
    }

    /** @return list<string> `php artisan <command>` names */
    public static function artisanCommands(string $code): array
    {
        preg_match_all('/php artisan ([a-z][a-z0-9:-]*)/', $code, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return list<string> upper-case names with an underscore, such as `FIELDSET_RELEASE_URL` or `APP_KEY` */
    public static function environmentVariables(string $code): array
    {
        preg_match_all('/(?<![A-Za-z0-9_.\/-])[A-Z][A-Z0-9]*_[A-Z0-9_]*[A-Z0-9](?![A-Za-z0-9_])/', $code, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * Raw HTML tags and `<placeholder>` words outside code spans and fenced blocks, such as `Weiter mit <organization>`
     * in prose: GitHub drops them as unknown HTML while the docs site shows them as text, so a guide puts them in a code
     * span (docs site plan U8).
     *
     * @return list<array{line: int, text: string}>
     */
    public static function rawTags(string $markdown): array
    {
        $prose = self::withoutCode($markdown);
        preg_match_all(self::RAW_TAG, $prose, $matches, PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $tag): array => ['line' => substr_count($prose, "\n", 0, $tag[1]) + 1, 'text' => $tag[0]], $matches[0]);
    }

    /** @return list<string> relative link targets without their fragment */
    public static function relativeLinks(string $markdown): array
    {
        preg_match_all('/\]\(([^)\s#]+)(?:#[^)]*)?\)/', $markdown, $matches);

        return array_values(array_unique(array_filter($matches[1], static fn (string $target): bool => preg_match('~^[a-z][a-z0-9+.-]*:~i', $target) !== 1)));
    }

    /**
     * The Markdown with fenced blocks (``` or ~~~, any indent) and code spans blanked out; line numbers stay.
     *
     * @throws RuntimeException for an unclosed fenced block or a failed match
     */
    private static function withoutCode(string $markdown): string
    {
        $fence = null;
        $lines = [];
        foreach (explode("\n", $markdown) as $number => $line) {
            $marker = preg_match('/^\s*(`{3,}|~{3,})(.*)$/', $line, $match) === 1 ? $match : null;
            if ($fence === null && $marker === null) {
                $lines[] = $line;

                continue;
            }
            if ($fence === null) {
                $fence = ['marker' => $marker[1], 'line' => $number + 1];
            } elseif ($marker !== null && str_starts_with($marker[1], $fence['marker']) && trim($marker[2]) === '') {
                $fence = null;
            }
            $lines[] = '';
        }
        if ($fence !== null) {
            throw new RuntimeException("Unclosed fenced block at line {$fence['line']}.");
        }

        return preg_replace_callback(self::CODE_SPAN, static fn (array $span): string => (string) preg_replace('/[^\n]/', ' ', $span[0]), implode("\n", $lines))
            ?? throw new RuntimeException('Code spans could not be read: '.preg_last_error_msg());
    }

    /** @return list<string> */
    private static function flags(string $segment): array
    {
        preg_match_all('/(?<![\w-])--([a-z][a-z0-9-]*)/', $segment, $matches);

        return array_values(array_unique($matches[1]));
    }
}
