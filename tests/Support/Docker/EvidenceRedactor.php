<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

/**
 * Removes what must never be committed from a journey transcript before InstanceJourney::evidence() writes it:
 * cookie values (Set-Cookie and Cookie, as header maps, lists or raw header lines), XSRF/CSRF header values, CSP
 * nonces, one-time sign-in links (R50), URL signatures and tokens, and fields named like tokens, secrets or
 * passwords. Names, cookie attributes and everything else stay, so the evidence still shows what was checked.
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
            return $key !== null && preg_match(self::COOKIE_KEY, $key) === 1
                ? array_map(static fn (mixed $item): mixed => is_string($item) ? self::cookies($item) : $item, $value)
                : self::transcript($value);
        }
        if (! is_string($value)) {
            return $value;
        }
        if ($key !== null && preg_match(self::SECRET_KEY, $key) === 1) {
            return $value === self::FSA_MASK ? $value : self::MASK; // fsa's own redaction stays visible
        }

        return self::text($key !== null && preg_match(self::COOKIE_KEY, $key) === 1 ? self::cookies($value) : $value);
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
