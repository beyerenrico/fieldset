<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Finds a secret passed as a command-line flag (R50, AE9) before the input is even parsed, so an undefined flag
 * such as `--smtp-password=…` exits 2 with the stdin/file hint instead of a generic parse error. A flag is
 * secret-looking when one of its words is a secret word; `-file`, `-stdin` and `-fd` variants are fine.
 */
final class SecretFlagGuard
{
    private const array SECRET_WORDS = ['password', 'passphrase', 'secret', 'token', 'key', 'apikey', 'credentials'];

    private const array SAFE_SUFFIXES = ['file', 'stdin', 'fd'];

    /** The first secret-looking option name in the raw input, without dashes. */
    public static function find(InputInterface $input): ?string
    {
        preg_match_all('/(?:^|\s)--([A-Za-z0-9][A-Za-z0-9_-]*)/', (string) $input, $matches);
        foreach ($matches[1] as $name) {
            if (self::isSecret(strtolower($name))) {
                return $name;
            }
        }

        return null;
    }

    public static function isSecret(string $option): bool
    {
        $words = preg_split('/[-_]/', $option) ?: [];
        if (in_array(end($words), self::SAFE_SUFFIXES, true)) {
            return false;
        }

        return array_any($words, fn (string $word, $index): bool => in_array($word, self::SECRET_WORDS, true) && ($word !== 'key' || ($words[$index - 1] ?? '') !== 'public'));
    }
}
