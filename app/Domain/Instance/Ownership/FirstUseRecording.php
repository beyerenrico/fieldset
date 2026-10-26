<?php

declare(strict_types=1);

namespace App\Domain\Instance\Ownership;

use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\Docker\OwnerSource;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\LockHeld;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\MeasuredOwner;
use Throwable;

/**
 * Existing instances record the owner on first use (2.1.1 U4, KTD6, R4). The 2.1.0 upgrade path runs old code, so nothing
 * measures during it; afterwards the 2.1.1 shim measures for every command until state.json holds a measured owner. After
 * a command whose class records the owner (FieldsetCommand::recordsOwner: the state-changing commands and doctor; never a
 * read-only one), the measurement of this run is saved (MeasuredOwner::record):
 *
 * - only a measurement of this run (FIELDSET_OWNER_SOURCE=measured) under a known runtime, whose owner is the saved one
 *   (`container_uid` changes only through `fieldset repair-ownership`), and only when state.json lacks it or holds
 *   another runtime or socket group (no rewrite on every doctor run);
 * - under the instance lock, taken without waiting and without taking over a stale lock, and with no operation unfinished:
 *   otherwise a later command records it.
 *
 * It never changes the command's result; a failure while writing warns and leaves state.json as it was.
 */
final readonly class FirstUseRecording
{
    public const string OPERATION = 'owner-record';

    public const string PHASE = 'owner';

    public function __construct(
        private InstanceDirectory $directory,
        private InstanceLock $lock,
        private ContainerIdentity $identity,
        private OperationJournal $journal,
    ) {}

    /** Saves the measured owner (true) or leaves state.json as it is (false). */
    public function afterCommand(HostFacts $facts, ProgressReporter $progress): bool
    {
        try {
            if (! $this->due($facts)) {
                return false;
            }
            $handle = $this->lock->acquire($this->identity->holder('fieldset', $facts->callerUid()), self::OPERATION, mayTakeOver: false);
        } catch (LockHeld) {
            return false;
        } catch (Throwable $e) {
            return $this->warn($progress, $e);
        }

        try {
            if ($this->journal->current() instanceof JournalRecord || ! $this->due($facts)) {
                return false;
            }
            $this->directory->saveState(MeasuredOwner::record($this->directory->state(), $facts));
        } catch (Throwable $e) {
            return $this->warn($progress, $e);
        } finally {
            $this->release($handle);
        }
        $progress->phase(self::PHASE, "Saved who owns the instance files inside containers (UID {$facts->containerUid}, measured just now) with the instance.");

        return true;
    }

    /**
     * A measurement of this run, of the saved owner, that state.json does not hold yet. It reads state.json, which another
     * command may change between the check without and the one under the lock.
     *
     * @phpstan-impure
     */
    private function due(HostFacts $facts): bool
    {
        if ($facts->ownerSource !== OwnerSource::Measured || ! $facts->runtime instanceof DockerRuntime || ! $this->directory->hasState()) {
            return false;
        }
        $state = $this->directory->state();

        return $facts->containerUid === $state->containerUid()
            && ($state->measuredRuntime() !== $facts->runtime || $state->socketGroup() !== $facts->socketGroup);
    }

    private function warn(ProgressReporter $progress, Throwable $e): bool
    {
        $progress->warn(self::PHASE, "Could not save who owns the instance files inside containers: {$e->getMessage()} A later command tries again.");

        return false;
    }

    private function release(LockHandle $handle): void
    {
        try {
            $this->lock->release($handle);
        } catch (Throwable) {
            // left to the next state-changing command, which takes over the lock of this exited container (InstanceLock)
        }
    }
}
