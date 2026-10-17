<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use SensitiveParameter;

/**
 * The instance's secrets (KTD7, §10): generated with a CSPRNG at install, kept only in the per-service env files
 * and read back from them on every render. Postgres keeps its first password, so a re-render must reuse the
 * existing values (U5 resume); secrets a service can rotate harmlessly (Valkey, catcher, dump role) are
 * generated again when their env file is gone.
 */
final readonly class InstanceSecrets
{
    /** Mailpit UI user; the password is the catcher secret. */
    public const string CATCHER_USER = 'fsa';

    public function __construct(
        #[SensitiveParameter] public string $appKey,
        #[SensitiveParameter] public string $dbPassword,
        #[SensitiveParameter] public string $valkeyPassword,
        #[SensitiveParameter] public string $catcherPassword,
        #[SensitiveParameter] public string $backupDbPassword,
        #[SensitiveParameter] public ?string $smtpUsername = null,
        #[SensitiveParameter] public ?string $smtpPassword = null,
    ) {}

    public static function generate(): self
    {
        return new self('base64:'.base64_encode(random_bytes(32)), self::password(), self::password(), self::password(), self::password());
    }

    /**
     * The secrets rendered earlier, or null when the app env file lacks APP_KEY or the database password: those
     * must never be regenerated silently, the database and every encrypted value depend on them.
     */
    public static function fromEnvFiles(EnvFiles $env): ?self
    {
        $app = $env->read('app');
        if (($app['APP_KEY'] ?? '') === '' || ($app['DB_PASSWORD'] ?? '') === '') {
            return null;
        }
        $catcher = $env->read('mailpit')['MP_UI_AUTH'] ?? '';
        $prefix = self::CATCHER_USER.':';

        return new self(
            $app['APP_KEY'],
            $app['DB_PASSWORD'],
            self::nonEmpty($app['REDIS_PASSWORD'] ?? null) ?? self::password(),
            str_starts_with($catcher, $prefix) && strlen($catcher) > strlen($prefix) ? substr($catcher, strlen($prefix)) : self::password(),
            self::nonEmpty($env->read('backup')['DB_PASSWORD'] ?? null) ?? self::password(),
            self::nonEmpty($app['MAIL_USERNAME'] ?? null),
            self::nonEmpty($app['MAIL_PASSWORD'] ?? null),
        );
    }

    public function withSmtpCredentials(#[SensitiveParameter] ?string $username, #[SensitiveParameter] ?string $password): self
    {
        return new self($this->appKey, $this->dbPassword, $this->valkeyPassword, $this->catcherPassword, $this->backupDbPassword, $username, $password);
    }

    public function withAppKey(#[SensitiveParameter] string $appKey): self
    {
        return new self($appKey, $this->dbPassword, $this->valkeyPassword, $this->catcherPassword, $this->backupDbPassword, $this->smtpUsername, $this->smtpPassword);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['secrets' => '[redacted]'];
    }

    /** 48 hex characters: safe in every env-file, URL and command-line position without quoting. */
    private static function password(): string
    {
        return bin2hex(random_bytes(24));
    }

    private static function nonEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
