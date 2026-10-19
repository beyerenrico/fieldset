<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * What `fsa status` and `fsa config show` say about restores (plan U17, ADR 0085), from the state, the journal and the
 * backups directory, without a database: the last restore (from the state's history), the storage volume the stack
 * mounts, whether webhooks wait after a move, an interrupted restore with the commands that resume or abandon it, and
 * scratch directories a killed restore or drill left.
 */
final readonly class RestoreStatus
{
    public function __construct(private InstanceDirectory $directory) {}

    /**
     * @return array{data: array<string, mixed>, warnings: list<array{0: string, 1: string, 2: string|null}>}
     */
    public function of(InstanceState $state, ?JournalRecord $open): array
    {
        $interrupted = $open?->operation() === RestoreOperation::OPERATION ? $this->interrupted($open) : null;
        $scratch = $open instanceof JournalRecord ? [] : $this->scratch(); // any open journal (upgrade, rollback, reset …) may own one (review U18 A7)
        $data = [
            'last' => self::last($state), 'storage_volume' => RestoreStorage::current($state),
            'webhooks_paused' => InstanceSettings::of($state)->webhooksPaused(), 'interrupted' => $interrupted, 'scratch_left' => $scratch,
        ];
        $warnings = [];
        if ($data['webhooks_paused']) {
            $warnings[] = ['webhooks_paused', 'Workflow webhooks are paused since this instance was installed from a backup.', 'Once the old server no longer runs, run `fsa config set webhooks on`.'];
        }
        if ($scratch !== []) {
            $warnings[] = ['restore_scratch_left', 'An interrupted restore or drill left scratch directories in backups/: '.implode(', ', $scratch).'. They may hold decrypted backup data.', 'Remove them (`rm -rf backups/.restore-*`) when no restore runs.'];
        }

        return ['data' => $data, 'warnings' => $warnings];
    }

    /**
     * The newest restore, rollbacks included (they restore a safety backup, plan U18): an automatic rollback names the
     * safety backup it restored as its backup.
     *
     * @return array{at: string|null, backup: string|null, epoch: int|null, safety_backup: string|null, event: string}|null
     */
    public static function last(InstanceState $state): ?array
    {
        $events = ['restored', 'installed_from_backup', 'rolled_back', 'upgrade_rolled_back'];
        $restores = array_values(array_filter($state->history(), static fn (array $entry): bool => in_array($entry['event'] ?? null, $events, true)));
        if ($restores === []) {
            return null;
        }
        $entry = $restores[array_key_last($restores)];
        $string = static fn (string $key): ?string => is_string($entry[$key] ?? null) ? $entry[$key] : null;
        $automatic = $entry['event'] === 'upgrade_rolled_back';

        return ['at' => $string('at'), 'backup' => $automatic ? $string('safety_backup') : $string('backup'), 'epoch' => is_int($entry['epoch'] ?? null) ? $entry['epoch'] : null,
            'safety_backup' => $automatic ? null : $string('safety_backup'), 'event' => (string) $entry['event']];
    }

    /** @return array<string, mixed> */
    private function interrupted(JournalRecord $record): array
    {
        $backup = is_string($record->context()['backup'] ?? null) ? $record->context()['backup'] : null;

        return [
            'backup' => $backup, 'last_phase' => $record->lastStartedPhase(),
            // a swap killed half-way may have renamed the databases or not (review A13)
            'swap' => match (true) {
                $record->hasCompleted('swap') => 'done', $record->hasStarted('swap') => 'interrupted', default => 'not_started'
            },
            'swapped' => $record->hasCompleted('swap') ? true : ($record->hasStarted('swap') ? null : false),
            'returning' => $record->hasStarted('return'), 'verified' => $record->hasCompleted('verify'),
            'resume' => 'fsa restore '.($backup ?? '<backup>'), 'abandon' => 'fsa restore --abandon',
        ];
    }

    /** @return list<string> */
    private function scratch(): array
    {
        $names = $this->directory->exists(InstanceDirectory::BACKUPS_DIR) ? $this->directory->files()->list(InstanceDirectory::BACKUPS_DIR) : [];

        return array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, RestoreSource::SCRATCH_PREFIX)));
    }
}
