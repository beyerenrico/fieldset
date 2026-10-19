<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use Throwable;

/**
 * The way back of an adopt that failed before 2.0 started (plan U19, ADR 0087): every container, network and volume
 * labelled with the new instance's UUID goes first (2.0 never served, so nothing of it is lost, and none of its
 * containers can hold a port 1.0 needs), then 1.0 starts again exactly as it ran (LegacyStack::restart; its volumes were
 * only read) and fsa checks that its containers run, then the files adopt generated go (state, compose file, env and
 * runtime files), so a later adopt starts fresh. The 1.0-format backup, the journal archive and the release stay. A
 * failure to start 1.0 again is exit 8 with the command that brings it back by hand; leftovers and 1.0 containers that
 * do not run are named.
 */
final readonly class AdoptRollback
{
    /** What adopt generated in the instance directory. */
    public const array GENERATED = [InstanceDirectory::STATE_FILE, InstanceDirectory::COMPOSE_FILE, InstanceDirectory::ENV_DIR, InstanceDirectory::RUNTIME_DIR];

    public function __construct(private InstanceDirectory $directory, private DockerGateway $docker, private LegacyStack $stack) {}

    /**
     * @return array{restarted: list<string>, not_running: list<string>, removed: list<string>, left: list<string>}
     *
     * @throws InstanceException exit 8 `rollback_failed` when 1.0 does not start again
     */
    public function undo(LegacyInstall $legacy, ?string $uuid): array
    {
        [$removed, $left] = $uuid === null ? [[], []] : $this->removeResources($uuid);
        try {
            $restarted = $this->stack->restart($legacy);
        } catch (Throwable $e) {
            throw new InstanceException(
                '1.0 could not be started again: '.$e->getMessage(),
                ExitCode::RollbackFailed, 'rollback_failed',
                "Start it by hand: cd {$legacy->checkout} && docker compose up -d (its volumes were not changed). Then run `fsa adopt --abandon` here to finish.",
                true, ['checkout' => $legacy->checkout, 'project' => $legacy->project, 'removed' => $removed, 'left' => $left], $e, true,
            );
        }
        foreach (self::GENERATED as $file) {
            $this->directory->files()->delete($file);
        }

        return ['restarted' => $restarted, 'not_running' => $this->stack->notRunning($legacy), 'removed' => $removed, 'left' => $left];
    }

    /** @return array{0: list<string>, 1: list<string>} removed, and what could not be removed */
    private function removeResources(string $uuid): array
    {
        $labels = [Labels::INSTANCE => $uuid];
        $removed = [];
        $left = [];
        $attempt = function (string $what, callable $remove) use (&$removed, &$left): void {
            try {
                $remove();
                $removed[] = $what;
            } catch (DockerCommandFailed) {
                $left[] = $what;
            }
        };
        foreach ($this->docker->containers($labels) as $container) {
            $attempt('container '.$container->name, fn () => $this->docker->removeContainer($container->id));
        }
        foreach ($this->docker->networks($labels) as $network) {
            $attempt('network '.$network->name, fn () => $this->docker->removeNetwork($network->name));
        }
        foreach ($this->docker->volumes($labels) as $volume) {
            $attempt('volume '.$volume->name, fn () => $this->docker->removeVolume($volume->name));
        }

        return [$removed, $left];
    }
}
