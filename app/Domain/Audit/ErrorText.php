<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Technical error texts before they are stored (mail log, send requests, render failures; §11 "secrets
 * never appear in logs"): known secrets, URL credentials and `key=value` secrets are masked, the
 * application path is dropped, control characters are removed and the text is truncated. Users never see
 * these texts directly; the UI shows fixed, translated messages per reason code.
 */
final class ErrorText
{
    public const int LIMIT = 500;

    private const string KEY_VALUE = '/\b(password|passwd|pass|secret|token|api[_-]?key|signature|authorization)(\s*[=:]\s*)("[^"]*"|[^\s&;,"]+)/i';

    /** @param  list<string|null>  $secrets  values to mask wherever they appear */
    public static function scrub(string $error, array $secrets = []): string
    {
        $text = str_replace(array_values(array_filter($secrets, static fn (?string $s): bool => $s !== null && $s !== '')), Redactor::MASK, $error);
        $text = (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^/\s:@]+:[^/\s@]*@#i', '$1'.Redactor::MASK.'@', $text);
        $text = (string) preg_replace(self::KEY_VALUE, '$1$2'.Redactor::MASK, $text);
        $text = (string) preg_replace('#(?<![\w./-])'.preg_quote(rtrim(base_path(), '/'), '#').'/#', '', $text);
        $text = trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $text));
        if ($text === '') {
            return '—';
        }

        return mb_strlen($text) > self::LIMIT ? mb_substr($text, 0, self::LIMIT - 1).'…' : $text;
    }
}
