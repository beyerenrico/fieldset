<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Instance-side tools of J10 (plan U15, ADR 0083): one-offs of the instance's app service from the host's Compose (the
 * 1.0 load generator and the perf fixtures need the app image and its storage volume), the edge's certificate chain for
 * Chromium's SPKI trust, the pull size of a release, the final container states and cgroup counters, the 1.0 perf
 * scripts with an instance's settings, and the install's phase timings from its NDJSON progress.
 */
final readonly class BudgetJourney
{
    /** Image roles a domain-mode install with capture mail pulls (no cloudflared). */
    public const array PULLED_ROLES = ['app', 'postgres', 'valkey', 'gotenberg', 'mailpit', 'caddy'];

    /** 100 Mbit/s in bytes per second: the reference link of BUILD_PROMPT_V2 §2.3. */
    private const int REFERENCE_BYTES_PER_SECOND = 12_500_000;

    private const array CLEARED_COMPOSE_ENV = ['COMPOSE_FILE', 'COMPOSE_PROJECT_NAME', 'COMPOSE_PROFILES', 'COMPOSE_ENV_FILES', 'COMPOSE_PATH_SEPARATOR'];

    /**
     * `docker compose run --rm --no-deps -T app …` against the instance's own compose file (as fsa's one-offs), so the
     * container gets the app's env file, networks, storage volume, labels and memory limit.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $containerEnv
     */
    public static function oneOff(string $dir, array $command, array $containerEnv = [], ?string $input = null, int $timeout = 3600): Process
    {
        $flags = array_merge(...array_map(static fn (string $key, string $value): array => ['-e', "{$key}={$value}"], array_keys($containerEnv), $containerEnv));
        $process = new Process(['docker', 'compose', '--ansi', 'never', '--file', $dir.'/compose.yaml', '--project-directory', $dir, 'run', '--rm', '--no-deps', '-T', ...$flags, 'app', ...$command],
            $dir, [...array_fill_keys(self::CLEARED_COMPOSE_ENV, false), 'COMPOSE_DISABLE_ENV_FILE' => '1']);
        $process->setInput($input);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }

    /**
     * The certificates the edge serves for `$domain` on 127.0.0.1:443 (leaf first), verified against `$root`.
     *
     * @return list<string> PEM
     */
    public static function servedChain(string $domain, string $root): array
    {
        $context = stream_context_create(['ssl' => ['cafile' => $root, 'verify_peer' => true, 'peer_name' => $domain, 'capture_peer_cert_chain' => true]]);
        $socket = @stream_socket_client('ssl://127.0.0.1:443', $errno, $error, 30, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException("TLS to the edge failed: {$error}");
        }

        return array_map(static function ($certificate): string {
            openssl_x509_export($certificate, $pem);

            return (string) $pem;
        }, stream_context_get_params($socket)['options']['ssl']['peer_certificate_chain'] ?? []);
    }

    /**
     * Compressed bytes per pulled image for one platform, from the release manifest, and the time at 100 Mbit/s.
     *
     * @return array{platform: string, images: array<string, int>, total_bytes: int, seconds_at_100_mbit: float}
     */
    public static function pullSize(HarnessRelease $release, string $platform): array
    {
        $images = [];
        foreach (self::PULLED_ROLES as $role) {
            $images[$role] = (int) ($release->manifest()['images'][$role]['platforms'][$platform]['compressed_size'] ?? 0);
        }
        $total = array_sum($images);

        return ['platform' => $platform, 'images' => $images, 'total_bytes' => $total, 'seconds_at_100_mbit' => round($total / self::REFERENCE_BYTES_PER_SECOND, 1)];
    }

    /**
     * The instance's long-running containers (no one-offs) as Docker sees them now.
     *
     * @return array<string, array<string, mixed>> service => state
     */
    public static function containers(string $uuid): array
    {
        $ids = self::lines(['docker', 'ps', '-aq', '--filter', 'label='.InstanceJourney::LABEL.'='.$uuid, '--filter', 'label=com.docker.compose.oneoff=False']);
        $inspected = $ids === [] ? [] : json_decode(self::run(['docker', 'inspect', ...$ids])->getOutput(), true);
        $states = [];
        foreach (is_array($inspected) ? $inspected : [] as $container) {
            $service = (string) ($container['Config']['Labels']['com.docker.compose.service'] ?? $container['Name']);
            $states[$service] = [
                'id' => $container['Id'], 'status' => $container['State']['Status'] ?? null, 'oom_killed' => $container['State']['OOMKilled'] ?? null,
                'restart_count' => $container['RestartCount'] ?? null, 'memory_limit_mib' => intdiv((int) ($container['HostConfig']['Memory'] ?? 0), 1048576),
                'cpuset' => $container['HostConfig']['CpusetCpus'] ?? '', 'nano_cpus' => $container['HostConfig']['NanoCpus'] ?? 0, 'started_at' => $container['State']['StartedAt'] ?? null,
            ];
        }
        ksort($states);

        return $states;
    }

    /**
     * cgroup v2 counters of a running container, read inside it: the memory high-water mark (page cache included), what
     * it holds now (anonymous, page cache, shared memory, kernel), how often it hit its limit (`max`), OOM kills of any
     * of its processes, CPU time and throttling, the effective CPUs.
     *
     * @return array<string, mixed>
     */
    public static function cgroup(string $id): array
    {
        $script = 'cd /sys/fs/cgroup && for f in memory.peak memory.events memory.stat cpu.stat cpuset.cpus.effective; do echo "== $f"; cat "$f"; done';
        $process = new Process(['docker', 'exec', $id, 'sh', '-c', $script]);
        $process->setTimeout(60);
        $process->run();
        if (! $process->isSuccessful()) {
            return ['error' => trim($process->getErrorOutput())];
        }
        $files = [];
        foreach (preg_split('/^== /m', $process->getOutput(), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $block) {
            [$name, $body] = array_pad(explode("\n", $block, 2), 2, '');
            $files[trim($name)] = trim($body);
        }
        $pairs = static fn (string $body): array => array_map(intval(...), array_column(array_map(static fn (string $line): array => array_pad(explode(' ', $line, 2), 2, '0'), array_filter(explode("\n", $body))), 1, 0));
        $events = $pairs($files['memory.events'] ?? '');
        $cpu = $pairs($files['cpu.stat'] ?? '');
        $stat = $pairs($files['memory.stat'] ?? '');
        $now = array_map(static fn (string $key): float => round(($stat[$key] ?? 0) / 1048576, 1), ['anon' => 'anon', 'file' => 'file', 'shmem' => 'shmem', 'kernel' => 'kernel']);

        return [
            'memory_peak_mib' => round((int) ($files['memory.peak'] ?? 0) / 1048576, 1), 'memory_now_mib' => $now, 'memory_events' => $events,
            'cpu_seconds' => round(($cpu['usage_usec'] ?? 0) / 1e6, 1), 'nr_throttled' => $cpu['nr_throttled'] ?? null, 'cpus_effective' => $files['cpuset.cpus.effective'] ?? null,
        ];
    }

    /** psql as the instance's database owner (a superuser in the official image) inside its postgres container. */
    public static function psql(string $postgres, string $sql): Process
    {
        $process = new Process(['docker', 'exec', $postgres, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'form_schema', '-d', 'form_schema', '-tAc', $sql]);
        $process->setTimeout(600);
        $process->run();

        return $process;
    }

    /**
     * Postgres' I/O since the last `pg_stat_reset()`: block reads outside shared buffers against hits, temp files, the
     * largest tables with their reads, the database size and the memory settings the budget derived.
     *
     * @return array<string, mixed>
     */
    public static function postgresStats(string $postgres): array
    {
        $sql = <<<'SQL'
            SELECT json_build_object(
                'database_mib', round(pg_database_size(current_database()) / 1048576.0), 'blks_read', blks_read, 'blks_hit', blks_hit,
                'hit_pct', round(100.0 * blks_hit / nullif(blks_hit + blks_read, 0), 1), 'temp_files', temp_files, 'temp_mib', round(temp_bytes / 1048576.0),
                'tables', (SELECT json_agg(t) FROM (SELECT relname, round(pg_total_relation_size(relid) / 1048576.0) AS mib, heap_blks_read, heap_blks_hit,
                    idx_blks_read, idx_blks_hit FROM pg_statio_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 6) t),
                'settings', (SELECT json_object_agg(name, current_setting(name)) FROM pg_settings WHERE name IN
                    ('shared_buffers', 'effective_cache_size', 'work_mem', 'maintenance_work_mem', 'max_connections', 'max_parallel_workers_per_gather', 'jit')))
            FROM pg_stat_database WHERE datname = current_database()
            SQL;
        $process = self::psql($postgres, $sql);
        $stats = json_decode(trim($process->getOutput()), true);

        return is_array($stats) ? $stats : ['error' => trim($process->getErrorOutput())];
    }

    /**
     * Waits until Horizon has nothing pending, delayed or reserved on `default` twice in a row (renders the scheduler's
     * documents:sweep dispatched while the load was being generated), at most `$timeout` seconds.
     *
     * @return array{seconds: float, settled: bool, largest_backlog: int}
     */
    public static function settleQueue(string $app, int $timeout = 900): array
    {
        $started = microtime(true);
        [$quiet, $largest] = [0, 0];
        while ($quiet < 2 && microtime(true) - $started < $timeout) {
            $monitor = new Process(['docker', 'exec', $app, 'php', 'artisan', 'queue:monitor', 'redis:default', '--max=1000000', '--json']);
            $monitor->setTimeout(60);
            $monitor->run();
            $queue = json_decode(trim($monitor->getOutput()), true)[0] ?? null;
            $backlog = is_array($queue) ? (int) $queue['size'] + (int) $queue['reserved'] : -1;
            $largest = max($largest, $backlog);
            $quiet = $backlog === 0 ? $quiet + 1 : 0;
            if ($quiet < 2) {
                sleep(5);
            }
        }

        return ['seconds' => round(microtime(true) - $started, 1), 'settled' => $quiet >= 2, 'largest_backlog' => $largest];
    }

    /**
     * Runs one of the 1.0 perf scripts (scripts/perf/*.mjs) with Node against the instance.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     */
    public static function perf(string $script, array $arguments, array $env): Process
    {
        $repository = TestImage::repository();
        $process = new Process(['node', "scripts/perf/{$script}", ...$arguments], $repository, ['PLAYWRIGHT_BROWSERS_PATH' => self::browsers(), ...$env]);
        $process->setTimeout(3 * 3600);
        $process->run();

        return $process;
    }

    /** Playwright's browsers: storage/playwright of this checkout or of the one node_modules links to (worktrees). */
    public static function browsers(): string
    {
        $repository = TestImage::repository();

        return is_dir($repository.'/storage/playwright') ? $repository.'/storage/playwright' : dirname((string) realpath($repository.'/node_modules')).'/storage/playwright';
    }

    /**
     * Phase durations of an install from its NDJSON progress lines, each stamped with its arrival (seconds since the
     * command started): install.sh's own pull (its `pull` line until `handover`) and every fsa phase's `elapsed_seconds`.
     *
     * @param  list<array{at: float, line: string}>  $lines
     * @return array{phases: array<string, float>, pull_seconds: float}
     */
    public static function phases(array $lines): array
    {
        $phases = [];
        $scriptPull = null;
        foreach ($lines as ['at' => $at, 'line' => $line]) {
            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }
            $phase = (string) ($event['phase'] ?? '');
            $message = (string) ($event['message'] ?? '');
            if ($phase === 'pull' && str_starts_with($message, 'Pulling ')) {
                $scriptPull = $at;
            } elseif ($phase === 'handover' && $scriptPull !== null) {
                $phases['install.sh pull'] = round($at - $scriptPull, 1);
            } elseif (isset($event['elapsed_seconds']) && str_starts_with($message, 'Completed ')) {
                $phases[$phase] = (float) $event['elapsed_seconds'];
            }
        }

        return ['phases' => $phases, 'pull_seconds' => round(($phases['install.sh pull'] ?? 0) + ($phases['pull'] ?? 0), 1)];
    }

    /** @param  list<string>  $command */
    private static function run(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(300);
        $process->mustRun();

        return $process;
    }

    /** @return list<string> */
    private static function lines(array $command): array
    {
        return array_values(array_filter(explode("\n", trim(self::run($command)->getOutput()))));
    }
}
