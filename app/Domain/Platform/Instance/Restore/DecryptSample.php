<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;

/**
 * Whether the instance's APP keys open the restored data (plan U17, R42, ADR 0085; the 1.0 restore drill's app check):
 * the first stored value of every encrypted column is decrypted with app.key and app.previous_keys (APP_PREVIOUS_KEYS),
 * so a backup taken under an earlier key still passes once that key is a previous key. A column that is missing (an
 * older schema) or holds no value is skipped. Values are never printed.
 */
final readonly class DecryptSample
{
    /** Encrypted columns (Eloquent `encrypted` casts and Fortify's two-factor secret). */
    public const array COLUMNS = [
        ['mail_messages', 'payload'], ['mail_settings', 'smtp_password'], ['webhook_endpoints', 'secret'],
        ['identity_providers', 'client_secret'], ['identity_providers', 'config'], ['users', 'two_factor_secret'],
    ];

    public function __construct(private ConnectionInterface $db, private Encrypter $encrypter) {}

    /** @return array{tested: int, failed: list<string>} */
    public function run(): array
    {
        $tested = 0;
        $failed = [];
        foreach (self::COLUMNS as [$table, $column]) {
            if (! $this->hasColumn($table, $column)) {
                continue;
            }
            $value = $this->db->table($table)->whereNotNull($column)->orderBy('id')->value($column);
            if (! is_string($value)) {
                continue;
            }
            $tested++;

            try {
                $this->encrypter->decrypt($value, false);
            } catch (DecryptException) {
                $failed[] = "{$table}.{$column}";
            }
        }

        return ['tested' => $tested, 'failed' => $failed];
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->selectOne(
            "SELECT 1 AS found FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?",
            [$table, $column],
        ) !== null;
    }
}
