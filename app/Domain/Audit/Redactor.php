<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Removes secrets from audit diffs and properties (BUILD_PROMPT §8.11, §11).
 */
final class Redactor
{
    public const string MASK = '[redacted]';

    private const string PATTERN = '/(password|secret|token|recovery|api[_-]?key|credential|private[_-]?key|smtp_pass|iban)/i';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::PATTERN, $key) === 1) {
                $clean[$key] = $value === null ? null : self::MASK;

                continue;
            }

            $clean[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $clean;
    }
}
