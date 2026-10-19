<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use SensitiveParameter;

/**
 * A short, non-secret fingerprint of an APP_KEY (R67, KTD18, ADR 0084): `fsa backup` prints it and every manifest lists
 * the fingerprints of the keys its HMAC key derives from, so a restore can tell which key a backup needs without the key
 * itself. HKDF-SHA256 with its own info string, so it reveals nothing about the HMAC key; 64 bits, `xxxx-xxxx-xxxx-xxxx`.
 */
final readonly class KeyFingerprint
{
    public const string INFO = 'form-schema-app app key fingerprint v1';

    public static function of(#[SensitiveParameter] string $appKey): string
    {
        $raw = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;

        return implode('-', str_split(substr(bin2hex(hash_hkdf('sha256', $raw, 32, self::INFO)), 0, 16), 4));
    }
}
