<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use Carbon\CarbonImmutable;

/**
 * The name of a backup directory in `backups/` (ADR 0084): `<kind>-<UTC yyyymmddThhmmssZ>`. Kinds: `scheduled` (the
 * backup service), `manual` (`fieldset backup`), `final` (uninstall's last backup, ADR 0073 as amended by the M12 gate) and
 * `safety-<operation>` (before an upgrade, restore, reset, rollback or adopt, R57). Only scheduled backups are ever
 * pruned (BackupRetention). A backup is written as `<name>.partial` and renamed once it is complete.
 */
final readonly class BackupName
{
    public const string SCHEDULED = 'scheduled';

    public const string MANUAL = 'manual';

    public const string FINAL = 'final';

    public const string SAFETY = 'safety';

    /** Operations that take a safety backup first (R57). */
    public const array SAFETY_OPERATIONS = ['upgrade', 'restore', 'reset', 'rollback', 'adopt'];

    public const string PARTIAL = '.partial';

    public const string PATTERN = '/^(scheduled|manual|final|safety-(?:upgrade|restore|reset|rollback|adopt))-(\d{8}T\d{6}Z)$/D';

    private const string STAMP = 'Ymd\THis\Z';

    private function __construct(public string $kind, public CarbonImmutable $at) {}

    /** @throws InstanceException exit 2 for an unknown kind */
    public static function for(string $kind, CarbonImmutable $at): self
    {
        $name = new self($kind, $at->utc()->startOfSecond());
        if (! self::parse($name->name()) instanceof BackupName) {
            throw new InstanceException("Unknown backup kind [{$kind}].", ExitCode::InvalidInput, 'invalid_backup_kind');
        }

        return $name;
    }

    public static function safety(string $operation, CarbonImmutable $at): self
    {
        return self::for(self::SAFETY.'-'.$operation, $at);
    }

    public static function parse(string $name): ?self
    {
        if (preg_match(self::PATTERN, $name, $match) !== 1) {
            return null;
        }
        $at = CarbonImmutable::createFromFormat(self::STAMP, $match[2], 'UTC');

        return $at instanceof CarbonImmutable && $at->format(self::STAMP) === $match[2] ? new self($match[1], $at) : null;
    }

    public function name(): string
    {
        return $this->kind.'-'.$this->at->format(self::STAMP);
    }

    public function partial(): string
    {
        return $this->name().self::PARTIAL;
    }

    public function isScheduled(): bool
    {
        return $this->kind === self::SCHEDULED;
    }

    public function isSafety(): bool
    {
        return str_starts_with($this->kind, self::SAFETY.'-');
    }

    /** The same kind one second later (two backups started within the same second). */
    public function next(): self
    {
        return new self($this->kind, $this->at->addSecond());
    }
}
