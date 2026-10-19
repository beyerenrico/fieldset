<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Restore\RestoreNames;
use App\Domain\Instance\State\RuntimeWriter;
use Closure;

/**
 * An open restore journal as a killed run leaves it (plan U17, ADR 0085), for RestoreFixture: every phase up to `$until`
 * completed, `$started` started too, the scratch database, fresh volume and scratch directory in place.
 */
final class InterruptedRestore
{
    private const array PHASES = ['plan', 'prepare', 'space', 'quiesce', 'ledger', 'safety_backup', 'restore_database', 'restore_storage', 'finish', 'erasures', 'swap', 'verify', 'open', 'gate'];

    /** @return RestoreNames the names its plan recorded */
    public static function journal(RestoreFixture $restore, string $until, ?string $started = null): RestoreNames
    {
        $journal = new OperationJournal($restore->instance->directory);
        $record = $journal->begin('restore', posix_getuid(), ['backup' => 'manual-20261019T120000Z', 'accept_unauthenticated' => false, 'identity_file' => null, 'key_file' => false]);
        $names = RestoreNames::for($restore->config->state(), $record->id());
        $data = self::data($names);
        foreach (self::PHASES as $phase) {
            $journal->startPhase($phase, [], ! in_array($phase, ['plan', 'prepare', 'space'], true));
            $journal->completePhase($phase, $data[$phase] ?? []);
            if ($phase === $until) {
                break;
            }
        }
        if ($started !== null) {
            $journal->startPhase($started);
        }
        if (array_search($until, self::PHASES, true) >= array_search('quiesce', self::PHASES, true)) {
            self::stopWriters($restore); // what the killed run's quiesce left (M12 gate D7: writers running on a resume are noticed)
        }
        $restore->databases[] = $names->scratch;
        $restore->instance->docker->createVolume($names->freshVolume, []);
        mkdir($restore->instance->directory->root.'/backups/.restore-'.$names->id, 0750, true);

        return $names;
    }

    /**
     * A return to the previous instance that began and stopped (exit 8, or killed): the verification failed, the
     * phase `return` started, with the journal notes its rename wrote (`returning_database`, `returned_database`).
     *
     * @param  list<string>  $notes
     */
    public static function returning(RestoreFixture $restore, array $notes): RestoreNames
    {
        $names = self::journal($restore, 'swap', started: 'verify');
        $journal = new OperationJournal($restore->instance->directory);
        $journal->failPhase('verify', 'The smoke test failed.');
        $journal->startPhase('return');
        foreach ($notes as $note) {
            $journal->note($note, ['live' => $names->live]);
        }

        return $names;
    }

    /** The databases after a swap renamed `$scratch` to the live name and the live one aside (the live name keeps its place). */
    public static function swapped(RestoreFixture $restore, RestoreNames $names): void
    {
        $restore->databases = [...array_values(array_diff($restore->databases, [$names->scratch])), $names->aside];
    }

    /** The databases after the first rename of a swap only: the live one aside, the live name free. */
    public static function halfSwapped(RestoreFixture $restore, RestoreNames $names): void
    {
        $restore->databases = [...array_values(array_diff($restore->databases, [$names->live])), $names->aside];
    }

    /**
     * A restore that failed before the swap and was killed while going back (phase `abort` started, or completed when the
     * kill came during the seal): the scratch database and the fresh volume may still be there.
     */
    public static function aborting(RestoreFixture $restore, bool $completed = false): RestoreNames
    {
        $names = self::journal($restore, 'restore_database');
        $journal = new OperationJournal($restore->instance->directory);
        $journal->startPhase('restore_storage');
        $journal->failPhase('restore_storage', 'tar failed');
        $journal->startPhase('abort', ['code' => 'storage_restore_failed', 'message' => 'tar failed']);
        if ($completed) {
            $journal->completePhase('abort', ['left_behind' => [], 'degraded' => []]);
        }

        return $names;
    }

    /** App, queue, scheduler and the backup service stopped, as a quiesce leaves them. */
    public static function stopWriters(RestoreFixture $restore): void
    {
        foreach ($restore->instance->docker->containers as $id => $container) {
            if (in_array($container->service(), ['app', 'queue', 'scheduler', 'backup'], true)) {
                $restore->instance->docker->stopContainer((string) $id);
            }
        }
    }

    /** The position of the first recorded Docker call (from `$from` on) that `$match(method, args)` accepts; -1 for none. */
    public static function position(RestoreFixture $restore, Closure $match, int $from = 0): int
    {
        foreach ($restore->instance->docker->calls as $index => $call) {
            if ($index >= $from && $match($call['method'], $call['args']) === true) {
                return $index;
            }
        }

        return -1;
    }

    /** Matches the restore-db one-off of `$action` (swap, rename, drop …). */
    public static function databaseCall(string $action): Closure
    {
        return static fn (string $method, array $args): bool => $method === 'composeRun' && ($args['command'][2] ?? null) === 'instance:restore-db' && ($args['command'][3] ?? null) === $action;
    }

    public static function raiseGate(RestoreFixture $restore): void
    {
        new RuntimeWriter($restore->instance->directory)->raiseGate($restore->config->state(), 'restore');
    }

    public static function gateRaised(string $dir): bool
    {
        return json_decode((string) file_get_contents($dir.'/runtime/runtime.json'), true)['gate']['raised'];
    }

    /** @return array<string, array<string, mixed>> */
    private static function data(RestoreNames $names): array
    {
        return [
            'plan' => $names->toArray(), 'prepare' => ['backup' => [], 'warnings' => [], 'add' => []], 'quiesce' => ['gate' => 'raised'],
            'ledger' => ['seed' => 1, 'epoch' => 1, 'input' => ['previous_heads' => [], 'previous_epoch' => 1, 'from_backup' => false]],
            'safety_backup' => ['backup' => 'safety-restore-20261019T120000Z'], 'restore_database' => ['database' => $names->scratch],
            'restore_storage' => ['volume' => $names->freshVolume], 'finish' => ['epoch' => 2], 'erasures' => ['removed' => 0],
            'swap' => ['flushed' => true, 'keys_added' => []], 'verify' => ['checked' => []], 'open' => ['open' => true], 'gate' => ['lowered' => true],
        ];
    }
}
