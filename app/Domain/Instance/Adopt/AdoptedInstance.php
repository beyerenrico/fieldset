<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Compose\Services\PostgresService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * What an adopted instance remembers about its 1.0 origin (plan U19, ADR 0087): the state's `adopted` history entry
 * (checkout, 1.0 project, backup) and the ledger's `adopted` line with the baseline snapshot taken when adopt finished.
 * `fsa uninstall` uses it: an adopted instance gets a final backup unless `--no-backup` (its 1.0 checkout holds the data
 * only up to the adopt), and the result reports the writes made since adopt (DataSnapshot::writesSince) and the command
 * that brings 1.0 back.
 */
final readonly class AdoptedInstance
{
    public function __construct(private InstanceDirectory $directory, private DockerGateway $docker, private DatabaseProbe $probe, private Ledger $ledger) {}

    /** @return array<string, mixed>|null the last `adopted` history entry */
    public static function record(?InstanceState $state): ?array
    {
        $entries = array_values(array_filter($state?->history() ?? [], static fn (array $entry): bool => ($entry['event'] ?? null) === AdoptOperation::EVENT));

        return $entries === [] ? null : $entries[array_key_last($entries)];
    }

    /**
     * The writes since adopt, read from the instance's running Postgres (the final backup started it); null when the
     * baseline or the database cannot be read.
     *
     * @return array{audit_entries: int, rows: array<string, int>, any: bool}|null
     */
    public function writesSince(InstanceState $state): ?array
    {
        $baseline = $this->ledger->last(AdoptOperation::EVENT)['baseline'] ?? null;
        if (! is_array($baseline)) {
            return null;
        }

        try {
            $project = ComposeProject::forInstance($state, $this->directory);
            $postgres = array_find(ComposeFile::serviceContainers($this->docker->composePs($project)), static fn (ContainerInfo $c): bool => $c->service() === PostgresService::NAME && $c->running());
            if (! $postgres instanceof ContainerInfo) {
                return null;
            }
            $settings = InstanceSettings::of($state);
            $now = $this->probe->snapshot($state->digests()['postgres'] ?? '', $postgres->id, $settings->dbDatabase(), $settings->dbUsername(), $state->uuid());

            return $now->writesSince(DataSnapshot::fromArray($baseline));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The `adopted` block of uninstall's result.
     *
     * @param  array<string, mixed>  $record
     * @param  array{audit_entries: int, rows: array<string, int>, any: bool}|null  $writes
     * @return array<string, mixed>
     */
    public static function uninstallData(array $record, ?array $writes): array
    {
        $checkout = (string) ($record['checkout'] ?? '');

        return ['checkout' => $checkout, 'project' => $record['project'] ?? null, 'backup' => $record['backup'] ?? null,
            'writes_since_adopt' => $writes, 'restart_1_0' => "cd {$checkout} && docker compose up -d"];
    }
}
