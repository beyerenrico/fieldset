<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use Symfony\Component\Process\Process;

/**
 * Reads containers' cgroup v2 memory.stat from the engine's cgroup tree for J10's EnvelopeMonitor (ADR 0083): one
 * throwaway container `fieldset-test-cgroup-<hex>` with the tree mounted read-only (no privileges, no network), so a sample
 * of every service costs one `docker exec` outside the measured containers instead of one inside each of them. It
 * removes itself after four hours at the latest (`--rm`, `sleep`). `memory.stat` tells what `docker stats` cannot:
 * anonymous memory and shared memory (Postgres' shared buffers), which the kernel cannot reclaim without swap.
 */
final readonly class CgroupReader
{
    private const int LIFETIME_SECONDS = 4 * 3600;

    private function __construct(public string $name) {}

    /** Starts the reader from an image that is present (it needs `sh`, `grep` and `sleep`), or null when that fails. */
    public static function start(string $image): ?self
    {
        $name = 'fieldset-test-cgroup-'.bin2hex(random_bytes(3));
        $process = new Process(['docker', 'run', '--detach', '--rm', '--name', $name, '--network', 'none', '--volume', '/sys/fs/cgroup:/host-cgroup:ro',
            '--entrypoint', 'sleep', $image, (string) self::LIFETIME_SECONDS]);
        $process->setTimeout(120);
        $process->run();

        return $process->isSuccessful() ? new self($name) : null;
    }

    /**
     * MiB of anonymous, shared and page-cache memory per container ID (cgroupfs or systemd layout).
     *
     * @param  list<string>  $ids  full container IDs
     * @return array<string, array{anon: float, shmem: float, file: float}>
     */
    public function read(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $script = 'for id in "$@"; do for d in /host-cgroup/docker/$id /host-cgroup/system.slice/docker-$id.scope; do '
            .'[ -r "$d/memory.stat" ] && echo "== $id" && grep -E "^(anon|shmem|file) " "$d/memory.stat"; done; done';
        $process = new Process(['docker', 'exec', $this->name, 'sh', '-c', $script, 'sh', ...$ids]);
        $process->setTimeout(30);
        $process->run();
        $stats = [];
        $id = null;
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if (str_starts_with($line, '== ')) {
                $id = substr($line, 3);
                $stats[$id] = ['anon' => 0.0, 'shmem' => 0.0, 'file' => 0.0];
            } elseif ($id !== null && preg_match('/^(anon|shmem|file) (\d+)$/', $line, $match) === 1) {
                $stats[$id][$match[1]] = round((int) $match[2] / 1048576, 1);
            }
        }

        return $stats;
    }

    public function stop(): void
    {
        new Process(['docker', 'rm', '--force', $this->name])->setTimeout(60)->run();
    }
}
