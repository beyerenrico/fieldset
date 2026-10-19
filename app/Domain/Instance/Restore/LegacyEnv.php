<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use SensitiveParameter;

/**
 * The few values a restore takes from a 1.0 `.env` (the `env` file `scripts/backup.sh --include-env` writes) or from an
 * fsa backup's `secrets.env` (R60, KTD20, plan U17, ADR 0085): an allow-list, nothing else is read. The APP keys decrypt
 * the restored data; the URL and the sender are portable settings. Everything that decides how the instance runs comes
 * from fsa's own rendering, so the 1.0 values that must not survive a move never do: `APP_DEBUG` (always false),
 * `SSO_ALLOW_INSECURE_IDP` (always false), `SSO_DNS_DRIVER` (always dns) and `TRUSTED_PROXIES` (from the mode) are not
 * read at all, and neither are SMTP credentials (a moved instance starts with capture mail).
 */
final readonly class LegacyEnv
{
    public const array ALLOWED = ['APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_URL', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'MAIL_HOST', 'MAIL_PORT'];

    /** What fsa renders whatever the 1.0 file said (ServiceEnvironments). */
    public const array FORCED = ['APP_DEBUG' => 'false', 'SSO_ALLOW_INSECURE_IDP' => 'false', 'SSO_DNS_DRIVER' => 'dns'];

    /** @return array<string, string> the allow-listed keys present in the file */
    public static function read(#[SensitiveParameter] string $contents): array
    {
        $values = [];
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z_][A-Z0-9_]*)\s*=(.*)$/D', $line, $match) !== 1 || ! in_array($match[1], self::ALLOWED, true)) {
                continue;
            }
            $value = self::value(trim($match[2]));
            if ($value !== '' && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
                $values[$match[1]] = $value;
            }
        }

        return $values;
    }

    private static function value(#[SensitiveParameter] string $raw): string
    {
        if (strlen($raw) >= 2 && $raw[0] === '"' && ($end = strrpos($raw, '"')) > 0) {
            return strtr(substr($raw, 1, $end - 1), ['\\"' => '"', '\\\\' => '\\', '\\n' => "\n"]);
        }
        if (strlen($raw) >= 2 && $raw[0] === "'" && ($end = strrpos($raw, "'")) > 0) {
            return substr($raw, 1, $end - 1);
        }

        return trim((string) preg_replace('/\s+#.*$/', '', $raw));
    }
}
