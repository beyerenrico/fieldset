<?php

declare(strict_types=1);

namespace Tests\Support\Docs;

use JsonException;
use RuntimeException;

/**
 * The runnable blocks of a guide (plan U21, J11): fenced blocks whose info string is `sh runnable`, each followed by a
 * `json expect` block with the exit code and the envelope fields the command must return. Placeholders in an expect
 * block stand for any value of a type (`"<string>"`, `"<int>"`, `"<bool>"`, `"<list>"`, `"<object>"`, `"<any>"`); every
 * other value must match exactly. Fields the expect block leaves out are not checked.
 */
final class GuideBlocks
{
    public const string RUNNABLE = 'sh runnable';

    public const string EXPECT = 'json expect';

    private const array TYPES = ['<string>', '<int>', '<bool>', '<list>', '<object>', '<any>'];

    /** @return list<array{info: string, body: string, line: int}> every fenced block, in order */
    public static function fenced(string $markdown): array
    {
        $blocks = [];
        $open = null;
        foreach (explode("\n", $markdown) as $index => $line) {
            if (preg_match('/^(\s*)```(.*)$/', $line, $match) !== 1) {
                if ($open !== null) {
                    $open['lines'][] = substr($line, min(strlen($open['indent']), strspn($line, ' ')));
                }

                continue;
            }
            if ($open === null) {
                $open = ['indent' => $match[1], 'info' => trim($match[2]), 'line' => $index + 1, 'lines' => []];

                continue;
            }
            $blocks[] = ['info' => $open['info'], 'body' => implode("\n", $open['lines']), 'line' => $open['line']];
            $open = null;
        }
        if ($open !== null) {
            throw new RuntimeException("Unclosed fenced block at line {$open['line']}.");
        }

        return $blocks;
    }

    /**
     * @return list<array{command: string, expect: array<string, mixed>, line: int}>
     *
     * @throws RuntimeException when a runnable block has no valid expect block right after it
     */
    public static function runnable(string $markdown): array
    {
        $blocks = self::fenced($markdown);
        $runnable = [];
        foreach ($blocks as $index => $block) {
            if ($block['info'] !== self::RUNNABLE) {
                continue;
            }
            $next = $blocks[$index + 1] ?? null;
            if ($next === null || $next['info'] !== self::EXPECT) {
                throw new RuntimeException("The runnable block at line {$block['line']} is not followed by a `json expect` block.");
            }
            $runnable[] = ['command' => trim($block['body']), 'expect' => self::expectation($next), 'line' => $block['line']];
        }

        return $runnable;
    }

    /**
     * The differences between an expect block and an envelope, as readable lines (empty: it matches).
     *
     * @return list<string>
     */
    public static function mismatches(mixed $expected, mixed $actual, string $path = ''): array
    {
        if (is_string($expected) && in_array($expected, self::TYPES, true)) {
            return self::typeMatches($expected, $actual) ? [] : ["{$path}: expected {$expected}, got ".self::show($actual)];
        }
        if (is_array($expected) && ! array_is_list($expected)) {
            if (! is_array($actual) || ($actual !== [] && array_is_list($actual))) {
                return ["{$path}: expected an object, got ".self::show($actual)];
            }
            $problems = [];
            foreach ($expected as $key => $value) {
                $child = $path === '' ? (string) $key : "{$path}.{$key}";
                $problems = [...$problems, ...(array_key_exists($key, $actual) ? self::mismatches($value, $actual[$key], $child) : ["{$child}: missing"])];
            }

            return $problems;
        }

        return $expected === $actual ? [] : ["{$path}: expected ".self::show($expected).', got '.self::show($actual)];
    }

    /** @return array<string, mixed> */
    private static function expectation(array $block): array
    {
        try {
            $expect = json_decode($block['body'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("The expect block at line {$block['line']} is not valid JSON: {$e->getMessage()}", 0, $e);
        }
        if (! is_array($expect) || ! is_int($expect['exit_code'] ?? null)) {
            throw new RuntimeException("The expect block at line {$block['line']} has no integer `exit_code`.");
        }

        return $expect;
    }

    private static function typeMatches(string $type, mixed $actual): bool
    {
        return match ($type) {
            '<string>' => is_string($actual) && $actual !== '',
            '<int>' => is_int($actual),
            '<bool>' => is_bool($actual),
            '<list>' => is_array($actual) && array_is_list($actual),
            '<object>' => is_array($actual) && ($actual === [] || ! array_is_list($actual)),
            default => true,
        };
    }

    private static function show(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
