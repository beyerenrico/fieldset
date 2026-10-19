<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Backup\BackupSchedule;
use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\SettingApplier;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fsa config set backup-schedule <HH:MM|off>` (E13, R32, ADR 0084): the daily time of scheduled backups in the instance's
 * time zone, or `off`. The time zone is the host's (FSA_TZ), recorded at install; a change that finds none recorded
 * records it now. Only the backup service is recreated (its env file changes); the change applies, verifies and
 * reverts like every key (ADR 0077, ADR 0078).
 */
final readonly class BackupScheduleApplier implements SettingApplier
{
    public const string KEY = 'backup-schedule';

    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        $value = strtolower(trim($request->value));
        if ($value !== BackupSchedule::OFF && ! BackupSchedule::isTime($value)) {
            throw new InvalidInput('The backup schedule must be a time of day as HH:MM (24 hours) or `off`.', 'invalid_value', 'For example `fsa config set backup-schedule 03:30`.');
        }

        return new ConfigChange(self::KEY, $state, $state->withSetting('backup_schedule', $value), InstanceSettings::of($state)->backupSchedule(), $value);
    }

    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        $zone = $facts->timezone;
        if ($change->after->setting('time_zone') !== null || $zone === null || ! BackupSchedule::isZone($zone)) {
            return $change;
        }

        return $change->withAfter($change->after->withSetting('time_zone', $zone));
    }
}
