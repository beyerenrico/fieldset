<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

/**
 * Removes what must never be committed from a journey transcript before InstanceJourney::evidence() writes it:
 * cookie values (Set-Cookie and Cookie, as header maps, lists or raw header lines), XSRF/CSRF header values, CSP
 * nonces, one-time sign-in links (R50), URL signatures and tokens, fields named like tokens, secrets or passwords,
 * every `value` in a `secrets` list (the envelope's one-time secrets: demo passwords, sign-in links, catcher password)
 * and every value of a `secrets` map (`config show`). Strings that hold JSON (raw stdout, NDJSON lines) are redacted
 * the same way inside. Names, cookie attributes and everything else stay, so the evidence still shows what was checked.
 */
final class EvidenceRedactor
{
    public const string MASK = '<redacted>';

    /** How `fsa config show` itself prints a secret. */
    private const string FSA_MASK = '[redacted]';

    /** Fields whose whole value is secret: *_token, *-token, *secret, *password (not token_type, not secrets lists). */
    private const string SECRET_KEY = '/(?:^|[_-])(?:token|secret|password)$/i';

    private const string COOKIE_KEY = '/^(?:set-)?cookie$/i';

    /** A cookie's name=value pair: the first pair of a cookie, never one of its attributes. */
    private const string COOKIE_PAIR = '/(^|[;,]\s*)(?!(?:expires|max-age|path|domain|samesite|priority|partitioned)=)([^=;,\s]+)=[^;,]*/i';

    /**
     * @param  array<array-key, mixed>  $transcript
     * @return array<array-key, mixed>
     */
    public static function transcript(array $transcript): array
    {
        $redacted = [];
        foreach ($transcript as $key => $value) {
            $redacted[$key] = self::value(is_string($key) ? $key : null, $value);
        }

        return $redacted;
    }

    private static function value(?string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            return match (true) {
                $key !== null && preg_match(self::COOKIE_KEY, $key) === 1 => array_map(static fn (mixed $item): mixed => is_string($item) ? self::cookies($item) : $item, $value),
                $key === 'secrets' => self::secrets($value),
                default => self::transcript($value),
            };
        }
        if (! is_string($value)) {
            return $value;
        }
        if ($key !== null && preg_match(self::SECRET_KEY, $key) === 1) {
            return $value === self::FSA_MASK ? $value : self::MASK; // fsa's own redaction stays visible
        }

        return self::json($value) ?? self::text($key !== null && preg_match(self::COOKIE_KEY, $key) === 1 ? self::cookies($value) : $value);
    }

    /**
     * An envelope's `secrets` list keeps each entry's name, `written_to` and expiry but never its `value`; a `secrets`
     * map (`config show`) keeps only fsa's own mask.
     *
     * @param  array<array-key, mixed>  $secrets
     * @return array<array-key, mixed>
     */
    private static function secrets(array $secrets): array
    {
        $masked = [];
        foreach ($secrets as $key => $secret) {
            $masked[$key] = match (true) {
                is_array($secret) => array_key_exists('value', $secret) ? [...self::transcript($secret), 'value' => self::mask($secret['value'])] : self::transcript($secret),
                is_string($secret) => self::mask($secret),
                default => $secret,
            };
        }

        return $masked;
    }

    private static function mask(mixed $value): mixed
    {
        return ! is_string($value) || $value === self::FSA_MASK ? $value : self::MASK;
    }

    /**
     * A string of JSON (one document, pretty or not, or one per line as NDJSON) with what it holds redacted; null when it
     * holds no JSON. A document with nothing to redact keeps its exact text.
     */
    private static function json(string $value): ?string
    {
        if (! str_contains($value, '{') && ! str_contains($value, '[')) {
            return null;
        }
        $whole = self::document($value, str_contains(trim($value), "\n") ? JSON_PRETTY_PRINT : 0);
        if ($whole !== null) {
            return $whole;
        }
        $lines = preg_split('/\R/', $value) ?: [];
        $found = false;
        foreach ($lines as $index => $line) {
            $redacted = self::document($line, 0);
            if ($redacted !== null) {
                $lines[$index] = $redacted;
                $found = true;
            }
        }

        return $found ? implode("\n", $lines) : null;
    }

    /** One JSON document, redacted and encoded again only when that changed something; null when it is none. */
    private static function document(string $text, int $flags): ?string
    {
        $decoded = in_array(ltrim($text)[0] ?? '', ['{', '['], true) ? json_decode($text, true) : null;
        if (! is_array($decoded)) {
            return null;
        }
        $redacted = self::transcript($decoded);

        return $redacted === $decoded ? $text : json_encode($redacted, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function cookies(string $header): string
    {
        return (string) preg_replace(self::COOKIE_PAIR, '$1$2='.self::MASK, $header);
    }

    private static function text(string $value): string
    {
        if (preg_match('/^((?:set-)?cookie:\s*)(.*)$/is', $value, $line) === 1) {
            return $line[1].self::cookies($line[2]);
        }
        if (preg_match('/^(x-(?:xsrf|csrf)-token:\s*).+$/is', $value, $line) === 1) {
            return $line[1].self::MASK;
        }

        return (string) preg_replace(
            ["/'nonce-[^']+'/", '/login-link#[A-Za-z0-9._~-]+/', '/([?&](?:signature|token)=)[^&#\s"]+/'],
            ["'nonce-".self::MASK."'", 'login-link#'.self::MASK, '$1'.self::MASK],
            $value,
        );
    }
}
