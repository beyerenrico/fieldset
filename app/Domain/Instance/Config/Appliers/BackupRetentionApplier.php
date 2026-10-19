<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Backup\BackupRetention;
use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\SettingApplier;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fsa config set backup-retention <n>` (E13, R32, ADR 0084): how many scheduled backups to keep, 1 to 365. Retention
 * applies after the next successful scheduled run and never touches manual, safety or kept backups (BackupRetention).
 */
final readonly class BackupRetentionApplier implements SettingApplier
{
    public const string KEY = 'backup-retention';

    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        $value = trim($request->value);
        if (preg_match('/^\d{1,3}$/D', $value) !== 1 || (int) $value < 1 || (int) $value > BackupRetention::MAX_KEEP) {
            throw new InvalidInput('Backup retention must be a whole number from 1 to '.BackupRetention::MAX_KEEP.'.', 'invalid_value', 'For example `fsa config set backup-retention 7`.');
        }

        return new ConfigChange(self::KEY, $state, $state->withSetting('backup_retention', (int) $value), (string) InstanceSettings::of($state)->backupRetention(), (string) (int) $value);
    }

    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        return $change;
    }
}
