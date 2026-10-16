<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Mapping;

/**
 * Recognizes Acrobat's built-in format and calculation scripts by text pattern (§8.13). The scripts
 * are never executed: anything that is not one of these exact calls counts as custom JavaScript.
 */
final class ScriptPatterns
{
    private const string STRING = '(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\')';

    /** Acrobat's AFDate_Format(n) table; entries 12 and 13 include a time. */
    private const array DATE_FORMATS = [
        'm/d', 'm/d/yy', 'mm/dd/yy', 'mm/yy', 'd-mmm', 'd-mmm-yy', 'dd-mmm-yy', 'yy-mm-dd', 'mmm-yy',
        'mmmm-yy', 'mmm d, yyyy', 'mmmm d, yyyy', 'm/d/yy h:MM tt', 'm/d/yy HH:MM',
    ];

    /**
     * The format of a text field: number/money (AFNumber), percent, date/time/datetime (AFDate,
     * AFTime), phone (AFSpecial 2), other AFSpecial, or `custom` for any other script.
     *
     * @return array{kind: string, decimals?: int, currency?: string, pattern?: string}|null null without a script
     */
    public static function format(?string $script): ?array
    {
        $script = self::stripComments($script);
        if ($script === null) {
            return null;
        }

        if (preg_match('/^AFNumber_(?:Format|Keystroke)\s*\(\s*(\d+)\s*,\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*'.self::STRING.'\s*,\s*(?:true|false)\s*(?:,[^)]*)?\)\s*;?$/s', $script, $m) === 1) {
            return ['kind' => 'number', 'decimals' => (int) $m[1], 'currency' => self::unescape(($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''))];
        }
        if (preg_match('/^AFPercent_(?:Format|Keystroke)\s*\(\s*(\d+)\s*,\s*\d+\s*(?:,[^)]*)?\)\s*;?$/s', $script, $m) === 1) {
            return ['kind' => 'percent', 'decimals' => (int) $m[1]];
        }
        if (preg_match('/^AF(Date|Time)_(?:Format|Keystroke)Ex\s*\(\s*'.self::STRING.'\s*\)\s*;?$/s', $script, $m) === 1) {
            return ['kind' => self::dateKind($m[1], self::unescape(($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''))), 'pattern' => self::unescape(($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''))];
        }
        if (preg_match('/^AF(Date|Time)_(?:Format|Keystroke)\s*\(\s*(\d+)\s*\)\s*;?$/s', $script, $m) === 1) {
            return ['kind' => $m[1] === 'Time' ? 'time' : self::dateKind('Date', self::DATE_FORMATS[(int) $m[2]] ?? 'm/d/yy')];
        }
        if (preg_match('/^AFSpecial_(?:Format|Keystroke)\s*\(\s*(\d)\s*\)\s*;?$/s', $script, $m) === 1) {
            return ['kind' => $m[1] === '2' ? 'phone' : 'special'];
        }
        if (preg_match('/^AFSpecial_KeystrokeEx\s*\(\s*'.self::STRING.'\s*\)\s*;?$/s', $script) === 1) {
            return ['kind' => 'special'];
        }

        return ['kind' => 'custom'];
    }

    /**
     * An Acrobat built-in calculation `AFSimple_Calculate("SUM", new Array("a", "b"))` (also with
     * `["a", "b"]` or a comma-separated string). Null for anything else (custom JavaScript).
     *
     * @return array{function: string, fields: list<string>}|null
     */
    public static function calculation(?string $script): ?array
    {
        $script = self::stripComments($script);
        if ($script === null) {
            return null;
        }

        $pattern = '/^(?:event\.value\s*=\s*)?AFSimple_Calculate\s*\(\s*["\'](SUM|PRD|AVG|MIN|MAX)["\']\s*,\s*(new\s+Array\s*\((.*)\)|\[(.*)\]|'.self::STRING.')\s*\)\s*;?$/s';
        if (preg_match($pattern, $script, $m) !== 1) {
            return null;
        }

        $list = ($m[3] ?? '') !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : null);
        $fields = $list !== null ? self::stringList($list) : self::commaList(self::unescape(($m[5] ?? '') !== '' ? $m[5] : ($m[6] ?? '')));

        return $fields === null || $fields === [] ? null : ['function' => $m[1], 'fields' => $fields];
    }

    /**
     * Currency code for an AFNumber currency string; null when the string is empty, '?' when unknown.
     */
    public static function currency(string $symbol): ?string
    {
        $symbol = trim($symbol);
        if ($symbol === '') {
            return null;
        }
        $upper = mb_strtoupper($symbol);

        return match (true) {
            $symbol === '€' || $upper === 'EUR' || $upper === 'EURO' => 'EUR',
            $symbol === '$' || $upper === 'US$' || $upper === 'USD' => 'USD',
            $symbol === '£' || $upper === 'GBP' => 'GBP',
            $symbol === '¥' || $upper === 'JPY' => 'JPY',
            in_array($upper, ['CHF', 'FR.', 'SFR.'], true) => 'CHF',
            $upper === 'ZŁ' || $upper === 'PLN' => 'PLN',
            preg_match('/^[A-Z]{3}$/', $symbol) === 1 => $symbol,
            default => '?',
        };
    }

    private static function dateKind(string $family, string $pattern): string
    {
        if ($family === 'Time') {
            return 'time';
        }
        $hasDate = preg_match('/[dmy]/', $pattern) === 1;
        $hasTime = preg_match('/[HhM]|tt/', $pattern) === 1;

        return match (true) {
            $hasDate && $hasTime => 'datetime',
            $hasTime => 'time',
            default => 'date',
        };
    }

    /** @return list<string>|null quoted strings separated by commas; null if anything else appears */
    private static function stringList(string $list): ?array
    {
        $rest = (string) preg_replace('/'.self::STRING.'/s', '', $list);
        if (trim(str_replace(',', '', $rest)) !== '' || preg_match_all('/'.self::STRING.'/s', $list, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        return array_values(array_filter(array_map(
            static fn (array $m): string => trim(self::unescape(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''))),
            $matches,
        ), static fn (string $name): bool => $name !== ''));
    }

    /** @return list<string> */
    private static function commaList(string $list): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $list)), static fn (string $name): bool => $name !== ''));
    }

    private static function unescape(string $value): string
    {
        return (string) preg_replace('/\\\\(.)/s', '$1', $value);
    }

    private static function stripComments(?string $script): ?string
    {
        if ($script === null) {
            return null;
        }
        $script = (string) preg_replace('~/\*.*?\*/~s', '', $script);
        $script = (string) preg_replace('~^\s*//.*$~m', '', $script);
        $script = trim($script);

        return $script === '' ? null : $script;
    }
}
