<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use SensitiveParameter;

/**
 * What the backup service knows, from its env file (ServiceEnvironments::backup(), KTD7, ADR 0084): the read-only dump
 * role, the HMAC key derived from APP_KEY (never APP_KEY itself), the age recipients (public keys only), the
 * schedule and retention, the owner UID:GID its output belongs to, and where the container mounts the instance
 * directory's parts and the storage volume. The env file is rendered from the state, so a change of these recreates the
 * service with fresh values. The instance's identity and portable settings come from `staging/backup/instance.json`.
 */
final readonly class BackupConfig
{
    public const string INSTANCE_ROOT = '/fieldset/instance';

    public const string STORAGE_PATH = '/var/www/html/storage/app';

    /**
     * @param  list<string>  $recipients
     * @param  list<string>  $fingerprints
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $database
     */
    public function __construct(
        public string $instanceRoot,
        public string $storagePath,
        public int $ownerUid,
        public int $ownerGid,
        public string $schedule,
        public string $timeZone,
        public int $retention,
        public int $minFreeMib,
        public array $recipients,
        #[SensitiveParameter] public string $hmacKey,
        public array $fingerprints,
        #[SensitiveParameter] public array $database,
        public string $appVersion,
    ) {}

    /**
     * @param  array<string, string>  $env  e.g. getenv()
     *
     * @throws InstanceException exit 2 when the env file lacks what a backup needs
     */
    public static function fromEnvironment(array $env): self
    {
        $value = static fn (string $key, string $default = ''): string => isset($env[$key]) && $env[$key] !== '' ? $env[$key] : $default;
        $list = static fn (string $key): array => array_values(array_filter(array_map(trim(...), explode(',', $value($key)))));

        return new self(
            $value('FIELDSET_INSTANCE_ROOT', self::INSTANCE_ROOT),
            $value('FIELDSET_BACKUP_STORAGE', self::STORAGE_PATH),
            self::int($value('FIELDSET_OWNER_UID', '0'), 'FIELDSET_OWNER_UID'),
            self::int($value('FIELDSET_OWNER_GID', '0'), 'FIELDSET_OWNER_GID'),
            $value('BACKUP_SCHEDULE', BackupSchedule::DEFAULT_TIME),
            $value('BACKUP_TIME_ZONE', 'UTC'),
            self::int($value('BACKUP_RETENTION', (string) BackupRetention::DEFAULT_KEEP), 'BACKUP_RETENTION'),
            self::int($value('BACKUP_MIN_FREE_MIB', (string) BackupRetention::DEFAULT_MIN_FREE_MIB), 'BACKUP_MIN_FREE_MIB'),
            $list('BACKUP_AGE_RECIPIENTS'),
            BackupChecksums::key($value('BACKUP_HMAC_KEY')),
            $list('BACKUP_APP_FINGERPRINTS'),
            [
                'host' => $value('DB_HOST', 'postgres'), 'port' => $value('DB_PORT', '5432'), 'database' => $value('DB_DATABASE'),
                'username' => $value('DB_USERNAME'), 'password' => $value('DB_PASSWORD'),
            ],
            $value('APP_VERSION', '0.0.0-dev'),
        );
    }

    public function schedule(): BackupSchedule
    {
        return BackupSchedule::parse($this->schedule, $this->timeZone);
    }

    public function encrypts(): bool
    {
        return $this->recipients !== [];
    }

    /** @return array<string, string> libpq's variables for pg_dump: passed in the environment, never as arguments */
    public function libpqEnvironment(): array
    {
        return [
            'PGHOST' => $this->database['host'], 'PGPORT' => $this->database['port'], 'PGDATABASE' => $this->database['database'],
            'PGUSER' => $this->database['username'], 'PGPASSWORD' => $this->database['password'], 'PGCONNECT_TIMEOUT' => '15',
        ];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['config' => '[redacted]'];
    }

    private static function int(string $value, string $key): int
    {
        if (preg_match('/^\d{1,9}$/D', $value) !== 1) {
            throw new InstanceException("{$key} must be a whole number.", ExitCode::InvalidInput, 'invalid_backup_config');
        }

        return (int) $value;
    }
}
