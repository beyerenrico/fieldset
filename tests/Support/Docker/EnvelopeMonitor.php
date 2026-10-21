<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The E16 envelope of J10 (plan U15, ADR 0083), as a background process next to the journey: it pins every container
 * of the instance under test (names containing `$match`, one-offs included; unnamed throwaways by the instance label,
 * once `<instance dir>/state.json` names the UUID) and every `fieldset` ops container that mounts the instance directory
 * to the same two CPUs (`docker update --cpuset-cpus`) as soon as Docker reports its creation, the way a
 * 2-vCPU host shares its two CPUs between all of them; memory limits are the generated compose file's own. It streams
 * `docker stats` and keeps per-container and per-phase peaks of memory (MiB, cache excluded, as `docker stats` counts)
 * and CPU (% of one CPU), the sum over the stack per refresh, and every `oom`, `die` and `restart` event of a tracked
 * container. The journey names the current phase in `<out>.phase`; the summary is rewritten to `<out>` every two
 * seconds and once more on SIGTERM. The monitor stops by itself when the journey's process is gone.
 */
final class EnvelopeMonitor
{
    private const int MAX_SECONDS = 4 * 3600;

    /** @var array<string, array<string, mixed>> container ID => what is known about it */
    private array $containers = [];

    /** @var array<string, array<string, mixed>> phase => peaks */
    private array $phases = [];

    /** @var list<array<string, mixed>> */
    private array $incidents = [];

    /** @var array{mem: float, cpu: float, seen: bool} */
    private array $block = ['mem' => 0.0, 'cpu' => 0.0, 'seen' => false];

    private string $phase = 'start';

    private bool $stopping = false;

    private ?string $uuid = null;

    private ?CgroupReader $reader = null;

    private bool $readerTried = false;

    /** @var array<string, string> stream name => unfinished line */
    private array $partial = ['events' => '', 'stats' => ''];

    public function __construct(private readonly string $out, private readonly string $match, private readonly string $cpuset, private readonly string $directory, private readonly int $parent) {}

    /** Starts the monitor for the instance directory `$directory` (container names contain its base name). */
    public static function spawn(string $out, string $directory, string $cpuset): Process
    {
        @unlink($out);
        file_put_contents($out.'.phase', 'start');
        $process = new Process([PHP_BINARY, TestImage::repository().'/tests/Install/Harness/envelope-monitor.php', $out, basename($directory), $cpuset, $directory, (string) getmypid()]);
        $process->setTimeout(null);
        $process->start();

        return $process;
    }

    public static function phase(string $out, string $phase): void
    {
        file_put_contents($out.'.phase', $phase);
    }

    /**
     * Stops the monitor and returns its final summary.
     *
     * @return array<string, mixed>
     */
    public static function finish(Process $process, string $out): array
    {
        if ($process->isRunning()) {
            $process->signal(SIGTERM);
            $until = microtime(true) + 15;
            while ($process->isRunning() && microtime(true) < $until) {
                usleep(100_000);
            }
            $process->stop(1);
        }
        $summary = json_decode((string) @file_get_contents($out), true);
        // A monitor killed before its own cleanup leaves its reader behind; it removes itself later anyway.
        if (is_array($summary) && is_string($summary['cgroup_reader'] ?? null)) {
            new Process(['docker', 'rm', '--force', $summary['cgroup_reader']])->setTimeout(60)->run();
        }

        return is_array($summary) ? $summary : throw new RuntimeException("The envelope monitor left no summary:\n".$process->getErrorOutput());
    }

    /** The two CPUs J10 pins the stack to: the engine's last two, away from CPU 0 and its interrupt work. */
    public static function cpuset(): string
    {
        $process = new Process(['docker', 'info', '--format', '{{.NCPU}}']);
        $process->mustRun();
        $cpus = (int) trim($process->getOutput());

        return $cpus >= 4 ? ($cpus - 2).','.($cpus - 1) : throw new RuntimeException("J10 needs an engine with at least 4 CPUs to pin two of them; it has {$cpus}.");
    }

    public function run(): int
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->stopping = true;
        });
        $events = $this->stream(['docker', 'events', '--filter', 'type=container', '--format', '{{json .}}']);
        $stats = $this->stream(['docker', 'stats', '--format', '{{json .}}']);
        $started = time();
        $written = 0;
        while (! $this->stopping && time() - $started < self::MAX_SECONDS && posix_kill($this->parent, 0)) {
            $this->phase = trim((string) @file_get_contents($this->out.'.phase')) ?: $this->phase;
            foreach ($this->lines('events', $events->getIncrementalOutput()) as $line) {
                $this->event($line);
            }
            foreach ($this->lines('stats', $stats->getIncrementalOutput()) as $line) {
                $this->sample($line);
            }
            if (time() - $written >= 2) {
                $this->readCgroups();
                $this->write(false);
                $written = time();
            }
            usleep(100_000);
        }
        $events->stop(1);
        $stats->stop(1);
        $this->reader?->stop();
        $this->write(true);

        return 0;
    }

    /** @param  list<string>  $command */
    private function stream(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(null);
        $process->start();

        return $process;
    }

    /** @return list<string> complete lines of a stream, escape sequences removed */
    private function lines(string $stream, string $chunk): array
    {
        $buffer = $this->partial[$stream].$chunk;
        $lines = explode("\n", $buffer);
        $this->partial[$stream] = array_pop($lines);

        return $lines;
    }

    private function event(string $line): void
    {
        $event = json_decode($line, true);
        if (! is_array($event)) {
            return;
        }
        $id = (string) ($event['Actor']['ID'] ?? '');
        $attributes = is_array($event['Actor']['Attributes'] ?? null) ? $event['Actor']['Attributes'] : [];
        $action = (string) ($event['Action'] ?? '');
        if ($action === 'create' && $this->matches($id, $attributes)) {
            $this->track($id, $attributes);
            $this->pin($id);

            return;
        }
        if (! isset($this->containers[$id])) {
            return;
        }
        if ($action === 'start') {
            $this->containers[$id]['running'] = true;
            if (! $this->containers[$id]['pinned']) {
                $this->pin($id);
            }
        } elseif ($action === 'die') {
            $this->containers[$id]['running'] = false;
            $this->containers[$id]['exit_codes'][] = (int) ($attributes['exitCode'] ?? -1);
        } elseif ($action === 'oom' || $action === 'restart') {
            $this->incident($id, $action, $attributes);
        }
    }

    /** @param  array<string, string>  $attributes */
    private function matches(string $id, array $attributes): bool
    {
        if (str_contains($attributes['name'] ?? '', $this->match)) {
            return true;
        }
        if ($this->uuid() !== null && ($attributes[InstanceJourney::LABEL] ?? '') === $this->uuid) {
            return true;
        }
        if (($attributes['app.fieldset.role'] ?? '') !== 'ops') {
            return false;
        }
        $binds = new Process(['docker', 'inspect', '--format', '{{json .HostConfig.Binds}}', $id]);
        $binds->run();

        return str_contains($binds->getOutput(), $this->directory.':');
    }

    private function uuid(): ?string
    {
        return $this->uuid ??= InstanceJourney::uuid($this->directory);
    }

    /** @param  array<string, string>  $attributes */
    private function track(string $id, array $attributes): void
    {
        $role = $attributes['app.fieldset.role'] ?? '';
        $this->containers[$id] = [
            'name' => $role === 'ops' ? 'ops:'.($attributes['name'] ?? $id) : ($attributes['name'] ?? $id),
            'service' => $attributes['com.docker.compose.service'] ?? ($role === 'ops' ? 'ops' : 'other'),
            'oneoff' => ($attributes['com.docker.compose.oneoff'] ?? '') === 'True',
            'image' => $attributes['image'] ?? '', 'running' => false,
            'created_in' => $this->phase, 'pinned' => false, 'pin_error' => null, 'pin_ms' => null,
            'mem_peak_mib' => 0.0, 'cpu_peak_pct' => 0.0, 'samples' => 0, 'exit_codes' => [],
            'anon_peak_mib' => null, 'anon_shmem_peak_mib' => null,
        ];
    }

    /**
     * Anonymous and shared memory of the running services (not one-offs or throwaways), through one CgroupReader started
     * from the first service's image: the part of a container's memory that page-cache reclaim cannot free.
     */
    private function readCgroups(): void
    {
        $services = array_filter($this->containers, static fn (array $c): bool => $c['running'] && ! $c['oneoff'] && ! in_array($c['service'], ['ops', 'other'], true));
        if ($services === []) {
            return;
        }
        if (! $this->reader instanceof CgroupReader && ! $this->readerTried) {
            $this->readerTried = true;
            $this->reader = CgroupReader::start((string) reset($services)['image']);
        }
        foreach ($this->reader?->read(array_keys($services)) ?? [] as $id => $memory) {
            $unreclaimable = $memory['anon'] + $memory['shmem'];
            $this->containers[$id]['anon_peak_mib'] = max($this->containers[$id]['anon_peak_mib'] ?? 0.0, $memory['anon']);
            $this->containers[$id]['anon_shmem_peak_mib'] = max($this->containers[$id]['anon_shmem_peak_mib'] ?? 0.0, $unreclaimable);
            $name = $this->containers[$id]['name'];
            $peaks = $this->phases[$this->phase]['containers'][$name] ?? ['mem_peak_mib' => 0.0, 'cpu_peak_pct' => 0.0];
            $this->phases[$this->phase]['containers'][$name] = [...$peaks, 'anon_shmem_peak_mib' => max($peaks['anon_shmem_peak_mib'] ?? 0.0, $unreclaimable)];
        }
    }

    private function pin(string $id): void
    {
        $started = microtime(true);
        $update = new Process(['docker', 'update', '--cpuset-cpus', $this->cpuset, $id]);
        $update->run();
        $this->containers[$id]['pinned'] = $update->isSuccessful();
        $this->containers[$id]['pin_error'] = $update->isSuccessful() ? null : trim($update->getErrorOutput());
        $this->containers[$id]['pin_ms'] = (int) round((microtime(true) - $started) * 1000);
    }

    /** @param  array<string, string>  $attributes */
    private function incident(string $id, string $action, array $attributes): void
    {
        $this->incidents[] = ['action' => $action, 'container' => $this->containers[$id]['name'], 'phase' => $this->phase, 'at' => gmdate('c'), 'exit_code' => $attributes['exitCode'] ?? null];
    }

    /**
     * One line of `docker stats`. Without a TTY each refresh starts with the cursor-home sequence ESC[H; lines may also
     * carry ESC[K (clear line) and the last one ESC[J, so only ESC[H separates refreshes.
     */
    private function sample(string $line): void
    {
        if (str_contains($line, "\033[H")) {
            $this->closeBlock();
        }
        $line = (string) preg_replace('/\033\[[0-9;]*[A-Za-z]/', '', $line);
        $stat = json_decode($line, true);
        $id = is_array($stat) ? (string) ($stat['Container'] ?? '') : '';
        if (! isset($this->containers[$id])) {
            return;
        }
        $mem = $this->mib(explode(' / ', (string) ($stat['MemUsage'] ?? '0B'))[0]);
        $cpu = (float) rtrim((string) ($stat['CPUPerc'] ?? '0'), '%');
        $container = &$this->containers[$id];
        $container['mem_peak_mib'] = max($container['mem_peak_mib'], $mem);
        $container['cpu_peak_pct'] = max($container['cpu_peak_pct'], $cpu);
        $container['samples']++;
        $peaks = $this->phases[$this->phase]['containers'][$container['name']] ?? ['mem_peak_mib' => 0.0, 'cpu_peak_pct' => 0.0];
        $this->phases[$this->phase]['containers'][$container['name']] = [...$peaks, 'mem_peak_mib' => max($peaks['mem_peak_mib'], $mem), 'cpu_peak_pct' => max($peaks['cpu_peak_pct'], $cpu)];
        $this->block = ['mem' => $this->block['mem'] + $mem, 'cpu' => $this->block['cpu'] + $cpu, 'seen' => true];
    }

    /** One `docker stats` refresh is complete: its sums are the stack's memory and CPU at that moment. */
    private function closeBlock(): void
    {
        if ($this->block['seen']) {
            $phase = $this->phases[$this->phase] ?? [];
            $this->phases[$this->phase] = [
                ...$phase,
                'stack_mem_peak_mib' => max($phase['stack_mem_peak_mib'] ?? 0.0, round($this->block['mem'], 1)),
                'stack_cpu_peak_pct' => max($phase['stack_cpu_peak_pct'] ?? 0.0, round($this->block['cpu'], 1)),
                'refreshes' => ($phase['refreshes'] ?? 0) + 1,
                'stack_cpu_sum_pct' => ($phase['stack_cpu_sum_pct'] ?? 0.0) + $this->block['cpu'],
            ];
        }
        $this->block = ['mem' => 0.0, 'cpu' => 0.0, 'seen' => false];
    }

    /** "123.4MiB", "1.2GiB", "512KiB", "0B" → MiB */
    private function mib(string $value): float
    {
        if (preg_match('/^([\d.]+)\s*([KMGT]i?B|B)$/', trim($value), $match) !== 1) {
            return 0.0;
        }
        $factor = ['B' => 1 / 1048576, 'KiB' => 1 / 1024, 'MiB' => 1, 'GiB' => 1024, 'TiB' => 1048576, 'kB' => 1 / 1048.576, 'KB' => 1 / 1048.576, 'MB' => 1 / 1.048576, 'GB' => 953.674][$match[2]] ?? 1;

        return round((float) $match[1] * $factor, 1);
    }

    private function write(bool $final): void
    {
        $phases = array_map(static function (array $phase): array {
            $refreshes = max(1, (int) ($phase['refreshes'] ?? 0));
            $phase['stack_cpu_mean_pct'] = round(($phase['stack_cpu_sum_pct'] ?? 0.0) / $refreshes, 1);
            unset($phase['stack_cpu_sum_pct']);

            return $phase;
        }, $this->phases);
        $summary = ['final' => $final, 'cpuset' => $this->cpuset, 'phase' => $this->phase, 'updated_at' => gmdate('c'), 'cgroup_reader' => $this->reader?->name,
            'containers' => array_values($this->containers), 'phases' => $phases, 'incidents' => $this->incidents];
        file_put_contents($this->out.'.tmp', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($this->out.'.tmp', $this->out);
    }
}
