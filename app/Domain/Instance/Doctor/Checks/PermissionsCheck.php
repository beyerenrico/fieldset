<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\State\InstanceDirectory;

/**
 * Who can change what the socket holder trusts (KTD2, ADR 0070): the instance directory, the state, the compose
 * file and the fieldset shim must not be writable by group or others, and must belong to the instance owner as
 * containers see it (the host UID on rootful Docker, 0 on rootless Docker and Docker Desktop). Anyone who can
 * edit them can make fieldset start containers of their choosing.
 */
final readonly class PermissionsCheck implements DoctorCheck
{
    private const array FILES = [
        '' => 'the instance directory',
        InstanceDirectory::STATE_FILE => 'state.json',
        InstanceDirectory::COMPOSE_FILE => 'compose.yaml',
        'fieldset' => 'the fieldset command',
    ];

    public function key(): string
    {
        return 'permissions';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $owner = $context->ownerUid();
        $problems = [];
        $paths = [];
        foreach (self::FILES as $relative => $label) {
            $path = $relative === '' ? $context->directory->root : $context->directory->root.'/'.$relative;
            $stat = $context->probes->stat($path);
            if ($stat === null) {
                continue;
            }
            if (($stat['mode'] & 0022) !== 0) {
                $problems[] = sprintf('%s is writable by group or others (mode %04o)', $label, $stat['mode']);
                $paths[] = $path;
            }
            if ($stat['uid'] !== $owner) {
                $problems[] = "{$label} belongs to UID {$stat['uid']}, not the instance owner (UID {$owner} inside containers)";
                $paths[] = $path;
            }
        }
        if ($problems !== []) {
            return CheckResult::fail($this->key(), ucfirst(implode('; ', $problems)).'.', 'Run as the instance owner: chmod go-w '.implode(' ', array_unique($paths)).' (and chown them to your user), then run the command again.', ['owner_uid' => $owner]);
        }

        return CheckResult::ok($this->key(), 'Only the instance owner can change the instance files.', ['owner_uid' => $owner]);
    }
}
