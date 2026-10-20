<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Overview;

use App\Domain\Instance\Runtime\RuntimeSource;
use App\Domain\Instance\Runtime\RuntimeState;
use App\Domain\Instance\Runtime\RuntimeStateReader;
use App\Domain\Instance\Runtime\TunnelState;
use App\Domain\Platform\Health\SystemProbes;
use App\Domain\Platform\Instance\BackupRuns;
use App\Domain\Platform\Instance\Updates\UpdateCheck;
use Carbon\CarbonImmutable;

/**
 * Everything the read-only `/platform` instance page shows (R39, R47, R48, plan U20, ADR 0089), read on each load from
 * what the app may read: the running image's version (config), runtime.json (mode, URLs, digests, catcher URL, whether
 * the update check is on), the `backup_runs` table, the disk under storage/ and the stored outcome of the update check.
 * Problems come first (InstanceProblems); an instance not run by fsa has no runtime file and shows what applies.
 */
final readonly class InstanceOverview
{
    public function __construct(
        private BackupRuns $runs,
        private SystemProbes $probes,
        private RuntimeStateReader $runtime,
        private UpdateCheck $updates,
    ) {}

    /**
     * @return array{
     *     problems: list<InstanceProblem>,
     *     release: array{version: string, revision: string|null, installed: string|null, digests: array<string, string>},
     *     access: array{fsa: bool, mode: string|null, url: string, local_url: string|null, tunnel: string|null},
     *     mail: array{capture: bool, transport: string, host: string|null, catcher_url: string|null},
     *     backup: array{schedule: string|null, time_zone: string|null, next_run: CarbonImmutable|null, last: array<string, mixed>|null, last_success: array<string, mixed>|null},
     *     disk: array{free: float, total: float, percent: int}|null,
     *     updates: array{enabled: bool, current: string, checked_at: CarbonImmutable|null, latest: string|null, available: bool, notes_url: string|null, notes_link: string|null, error: string|null}
     * }
     */
    public function build(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $fsa = is_string(config('instance.runtime_path')) && config('instance.runtime_path') !== '';
        $state = $this->state();
        $summary = $this->runs->summary($now);
        $space = $this->probes->diskSpace(storage_path());
        $updates = $this->updates();
        $silent = $fsa && $summary['last'] === null && $summary['service'] === null;

        $problems = InstanceProblems::backups($summary['alerts'], $silent);
        $problems[] = InstanceProblems::disk($space);
        $problems[] = InstanceProblems::update($updates['available'] ? $updates['latest'] : null);
        $problems[] = $fsa && $summary['last_success'] !== null ? InstanceProblems::onHost() : null;

        return [
            'problems' => array_values(array_filter($problems)),
            'release' => $this->release($state),
            'access' => $this->access($fsa, $state, $now),
            'mail' => $this->mail($state),
            'backup' => [
                'schedule' => is_string($summary['service']['schedule'] ?? null) ? $summary['service']['schedule'] : null,
                'time_zone' => is_string($summary['service']['time_zone'] ?? null) ? $summary['service']['time_zone'] : null,
                'next_run' => $summary['next_run'] === null ? null : CarbonImmutable::parse($summary['next_run']),
                'last' => $summary['last'],
                'last_success' => $summary['last_success'],
            ],
            'disk' => $space === null ? null : [...$space, 'percent' => (int) floor($space['free'] / $space['total'] * 100)],
            'updates' => $updates,
        ];
    }

    /** The runtime state from the file (or its last good value); none for an instance not run by fsa or without a good file. */
    private function state(): ?RuntimeState
    {
        $reading = $this->runtime->reading();

        return in_array($reading->source, [RuntimeSource::File, RuntimeSource::LastGood], true) ? $reading->state : null;
    }

    /** @return array{version: string, revision: string|null, installed: string|null, digests: array<string, string>} */
    private function release(?RuntimeState $state): array
    {
        $revision = config('app.revision');

        return [
            'version' => (string) config('app.version'),
            'revision' => is_string($revision) && $revision !== '' ? $revision : null,
            'installed' => $state?->instance?->version,
            'digests' => $state->instance->digests ?? [],
        ];
    }

    /** @return array{fsa: bool, mode: string|null, url: string, local_url: string|null, tunnel: string|null} */
    private function access(bool $fsa, ?RuntimeState $state, CarbonImmutable $now): array
    {
        $tunnel = $state?->tunnel;

        return [
            'fsa' => $fsa,
            'mode' => $state?->instance?->mode,
            'url' => $state->instanceUrl ?? (string) config('app.url'),
            'local_url' => $state?->localUrl,
            'tunnel' => $tunnel instanceof TunnelState && $tunnel->isActive($now) ? $tunnel->hostname : null,
        ];
    }

    /** @return array{capture: bool, transport: string, host: string|null, catcher_url: string|null} */
    private function mail(?RuntimeState $state): array
    {
        $mailer = (string) config('mail.default', 'log');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        $host = config("mail.mailers.{$mailer}.host");

        return [
            'capture' => config('mail.capture') === true,
            'transport' => $transport,
            'host' => $transport === 'smtp' && is_string($host) && $host !== '' ? $host : null,
            'catcher_url' => $state?->instance?->catcherUrl,
        ];
    }

    /** @return array{enabled: bool, current: string, checked_at: CarbonImmutable|null, latest: string|null, available: bool, notes_url: string|null, notes_link: string|null, error: string|null} */
    private function updates(): array
    {
        $current = (string) config('app.version');
        $enabled = $this->updates->enabled();
        $status = $enabled ? $this->updates->last() : null;

        return [
            'enabled' => $enabled,
            'current' => $current,
            'checked_at' => $status?->checkedAt,
            'latest' => $status?->latest,
            'available' => $status?->availableFor($current) ?? false,
            'notes_url' => $status?->notesUrl,
            'notes_link' => $status?->notesLink(),
            'error' => $status?->error,
        ];
    }
}
