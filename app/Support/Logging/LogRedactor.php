<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Domain\Audit\Redactor;

/**
 * Secrets never appear in logs (BUILD_PROMPT §11): the audit Redactor's key patterns (password, token,
 * secret, …) plus credential-bearing HTTP headers, at any depth. redact() keeps objects as they are;
 * redactPlain() turns them into arrays first.
 */
final class LogRedactor
{
    private const string HEADERS = '/^(authorization|proxy-authorization|cookie|set-cookie|php_auth_pw|x-api-key)$/i';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        return self::headers(Redactor::redact($data));
    }

    /**
     * Objects (models, DTOs, plain objects) first become the JSON they serialize to, so their keys are
     * redacted too; values that cannot be encoded are dropped.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redactPlain(array $data): array
    {
        $json = json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        $plain = is_string($json) ? json_decode($json, true) : null;

        return self::redact(is_array($plain) ? $plain : []);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function headers(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::HEADERS, $key) === 1) {
                $data[$key] = $value === null ? null : Redactor::MASK;
            } elseif (is_array($value)) {
                $data[$key] = self::headers($value);
            }
        }

        return $data;
    }
}
