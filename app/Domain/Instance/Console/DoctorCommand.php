<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Doctor\Doctor;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Doctor\DoctorFailed;
use App\Domain\Instance\Doctor\DoctorProbes;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fieldset doctor` (§7, R10, ADR 0072): Docker and Compose versions, architecture, memory, disk, path sharing,
 * permissions of the instance files, stale hand-over files (removed), DNS in domain mode, registry reachability,
 * clock skew, ports and, in proxy mode, whether the trusted proxy address still matches (`proxy_address_changed`);
 * with --post also the running services. Any failed check is exit 3 (10 for a too old
 * Docker) with the full report under `data.checks`; warnings and `unknown` results (a check that could not find out,
 * 2.1.1 U4) pass with exit 0. The permissions check compares the owner the shim measured with the saved one; the first
 * doctor under 2.1.1 saves a matching measurement with the instance (recordsOwner, KTD6).
 */
#[Description('Preflight and health checks of the host and the instance')]
#[Signature('fieldset:doctor {--post : Also check that every service runs and is healthy (after install or upgrade)}')]
final class DoctorCommand extends FieldsetCommand
{
    /** Doctor measures every time; the first doctor under 2.1.1 saves the measured owner with the instance (KTD6). */
    protected function recordsOwner(): bool
    {
        return true;
    }

    protected function perform(FieldsetContext $context): CommandResult
    {
        $directory = $context->directory();
        $state = $directory->hasState() ? $directory->state() : null;
        $domain = $state?->setting('domain');
        $report = $this->laravel->make(Doctor::class)->run(new DoctorContext(
            $context->facts, $directory, $this->laravel->make(DockerGateway::class), $this->laravel->make(DoctorProbes::class), $state,
            domain: $state?->mode() === 'domain' && is_string($domain) ? $domain : null,
            appImage: $state?->digests()['app'] ?? null,
            images: $this->images($state, ComposeFile::document($directory)),
            uuid: $state?->uuid(),
            post: (bool) $this->option('post'),
            lanAddress: $state instanceof InstanceState && InstanceSettings::of($state)->localCa() ? InstanceSettings::of($state)->lanAddress() : null,
        ));
        if (! $report->passed()) {
            throw new DoctorFailed($report);
        }
        $result = CommandResult::ok('doctor', ['checks' => $report->toArray(), 'post' => (bool) $this->option('post')]);
        foreach ($report->warnings() as $warning) {
            $result = $result->withWarning($warning->warningCode(), $warning->summary, $warning->hint);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $compose
     * @return list<string> images the instance runs: those of the compose file, else the app image
     */
    private function images(?InstanceState $state, array $compose): array
    {
        $images = [];
        foreach (is_array($compose['services'] ?? null) ? $compose['services'] : [] as $service) {
            if (is_array($service) && is_string($service['image'] ?? null)) {
                $images[] = $service['image'];
            }
        }
        $app = $state?->digests()['app'] ?? null;

        return array_values(array_unique($images === [] && $app !== null ? [$app] : $images));
    }
}
