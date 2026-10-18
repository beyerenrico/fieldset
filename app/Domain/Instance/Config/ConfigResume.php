<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\Install\AcmeCaRoot;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\LockHeld;
use App\Domain\Instance\Operations\LockRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationUnfinished;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;

/**
 * The rules for an unfinished `config set` (R54, ADR 0077, ADR 0078):
 *
 * - A live lock holder refuses any `config set` up front (exit 9), journal or not, before the URL-impact one-off or a
 *   prompt: nothing may run against an instance another command is changing.
 * - Another unfinished operation blocks (exit 9). An unfinished `config set` resumes only with the same key, value
 *   and options, and the exact command (options included) is named otherwise.
 * - A resume takes the change from the journal (the snapshot's previous state and the preflight's target), never
 *   from a new plan: planning may ask the engine (a proxy's address), which must not block finishing a change, and
 *   the earlier run's target is what was applied.
 */
final readonly class ConfigResume
{
    public function __construct(
        private InstanceDirectory $directory,
        private OperationJournal $journal,
        private InstanceLock $lock,
    ) {}

    /** @throws LockHeld while a live command holds the instance lock */
    public function refuseLiveLock(): void
    {
        $holder = $this->lock->current();
        if ($holder instanceof LockRecord && ! $this->lock->isStale($holder)) {
            throw new LockHeld($holder);
        }
    }

    /**
     * The unfinished `config set` journal this request resumes, or null when there is none.
     *
     * @throws OperationUnfinished for another operation or another `config set`
     */
    public function unfinished(ConfigRequest $request): ?JournalRecord
    {
        $current = $this->journal->current();
        if (! $current instanceof JournalRecord) {
            return null;
        }
        if ($current->operation() !== ConfigSetter::OPERATION || ! $request->sameAs($current->context())) {
            throw new OperationUnfinished($current);
        }

        return $current;
    }

    /**
     * The change an unfinished journal recorded: previous state from the snapshot, target from the preflight. Null
     * when the earlier run stopped before its preflight completed (then nothing changed and a new plan is right).
     */
    public function change(JournalRecord $record, ConfigRequest $request): ?ConfigChange
    {
        $snapshot = $record->phaseData('snapshot');
        $preflight = $record->phaseData('preflight');
        if (! is_array($snapshot['previous'] ?? null) || ! is_array($preflight['after'] ?? null)) {
            return null;
        }
        $after = InstanceState::fromArray($preflight['after']);
        $digest = $after->setting('acme_ca_root');
        $caRoot = is_string($digest) && $request->acmeCaRoot !== null && ! AcmeCaRoot::present($this->directory, $digest) ? $request->acmeCaRoot : null;

        return new ConfigChange(
            $request->key,
            InstanceState::fromArray($snapshot['previous']),
            $after,
            is_string($snapshot['from'] ?? null) ? $snapshot['from'] : null,
            is_string($preflight['to'] ?? null) ? $preflight['to'] : (is_string($snapshot['to'] ?? null) ? $snapshot['to'] : null),
            $caRoot,
        );
    }

    /** The previous state an earlier attempt journaled, for planning a resume that has no preflight yet. */
    public function previous(JournalRecord $record): ?InstanceState
    {
        $previous = $record->phaseData('snapshot')['previous'] ?? null;

        return is_array($previous) ? InstanceState::fromArray($previous) : null;
    }
}
