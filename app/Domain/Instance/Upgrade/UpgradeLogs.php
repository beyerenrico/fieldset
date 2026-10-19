<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Output\SecretRedactor;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;

/**
 * Container log lines an upgrade keeps (plan U18, ADR 0086): the failing target's or child's last lines go into the
 * journal and the result, so every secret value of the instance's env files is masked first (R50), as the console's
 * redactor masks the printed envelope.
 */
final readonly class UpgradeLogs
{
    public static function redact(InstanceDirectory $directory, string $logs): string
    {
        if ($logs === '') {
            return '';
        }
        $redactor = new SecretRedactor;
        foreach (new EnvFiles($directory)->secretValues() as $secret) {
            $redactor->register($secret);
        }

        return $redactor->redactString($logs);
    }
}
