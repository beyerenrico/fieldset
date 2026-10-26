<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use Throwable;

/**
 * Work after a command that is done only while no other operation holds the instance lock (2.1.1 U4 FirstUseRecording,
 * U15 ScriptRefresh): the lock is taken without waiting and without taking over a stale one. While another operation
 * holds it nothing happens (false) and a later command tries again; a failure while planning, taking the lock or working
 * goes to `$failed`, whose answer is returned. The lock is released however the work ended.
 */
final readonly class BestEffortLock
{
    public function __construct(private InstanceLock $lock, private ContainerIdentity $identity) {}

    /**
     * @template T
     *
     * @param  callable(): (T|null)  $plan  before the lock: what to do, or null for nothing (false)
     * @param  callable(T): bool  $work  under the lock
     * @param  callable(Throwable): bool  $failed
     */
    public function attempt(int $callerUid, string $operation, callable $plan, callable $work, callable $failed): bool
    {
        try {
            $planned = $plan();
            if ($planned === null) {
                return false;
            }
            $handle = $this->lock->acquire($this->identity->holder('fieldset', $callerUid), $operation, mayTakeOver: false);
        } catch (LockHeld) {
            return false;
        } catch (Throwable $e) {
            return $failed($e);
        }

        try {
            return $work($planned);
        } catch (Throwable $e) {
            return $failed($e);
        } finally {
            $this->release($handle);
        }
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
