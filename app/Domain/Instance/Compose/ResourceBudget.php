<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * Memory limits per service and the process counts that must fit them (E16, ADR 0062, plan U3).
 *
 * The default size is the E16 floor: a 2 GB host minus OS headroom (kernel reservation, systemd, sshd, journald,
 * dockerd/containerd and one shim per container) must hold the worst case of every limit together: domain mode
 * with capture mail, the edge and the backup service (U8, U16) included. Edge and tunnel never run together
 * (R61), so they share one slot. Larger sizes scale Horizon processes, Gotenberg concurrency and FrankenPHP
 * threads. The numbers are initial estimates; U15 measures them under a 2 GB envelope and tunes this table.
 */
final readonly class ResourceBudget
{
    public const array SIZES = ['small', 'medium', 'large'];

    public const string DEFAULT_SIZE = 'small';

    /** Gotenberg queue slots per concurrent conversion: room for every Horizon worker and the health check. */
    private const int GOTENBERG_QUEUE_PER_SLOT = 8;

    /**
     * MiB per size. `limits` covers every service the renderers know or will render (edge U8, tunnel U14, backup
     * U16); a service without a slot cannot be rendered.
     */
    private const array PROFILES = [
        'small' => [
            'host' => 2048, 'headroom' => 320,
            'limits' => [
                'app' => 384, 'queue' => 320, 'scheduler' => 128, 'postgres' => 256, 'redis' => 80,
                'gotenberg' => 320, 'mailpit' => 48, 'edge' => 64, 'tunnel' => 64, 'backup' => 128,
            ],
            'horizon' => 2, 'threads' => 4, 'chromium' => 1, 'pg_connections' => 40, 'mail_messages' => 500,
        ],
        'medium' => [
            'host' => 4096, 'headroom' => 512,
            'limits' => [
                'app' => 704, 'queue' => 704, 'scheduler' => 192, 'postgres' => 640, 'redis' => 160,
                'gotenberg' => 640, 'mailpit' => 64, 'edge' => 96, 'tunnel' => 96, 'backup' => 192,
            ],
            'horizon' => 4, 'threads' => 8, 'chromium' => 2, 'pg_connections' => 60, 'mail_messages' => 1000,
        ],
        'large' => [
            'host' => 8192, 'headroom' => 768,
            'limits' => [
                'app' => 1536, 'queue' => 1536, 'scheduler' => 256, 'postgres' => 1536, 'redis' => 384,
                'gotenberg' => 1280, 'mailpit' => 96, 'edge' => 128, 'tunnel' => 128, 'backup' => 256,
            ],
            'horizon' => 8, 'threads' => 16, 'chromium' => 4, 'pg_connections' => 100, 'mail_messages' => 2000,
        ],
    ];

    /** @param  array{host: int, headroom: int, limits: array<string, int>, horizon: int, threads: int, chromium: int, pg_connections: int, mail_messages: int}  $profile */
    private function __construct(private string $size, private array $profile) {}

    public static function forSize(string $size): self
    {
        if (! array_key_exists($size, self::PROFILES)) {
            throw new InstanceException("Unknown instance size [{$size}]; use one of ".implode(', ', self::SIZES).'.', ExitCode::InvalidInput, 'invalid_size');
        }

        return new self($size, self::PROFILES[$size]);
    }

    public function size(): string
    {
        return $this->size;
    }

    public function hostMib(): int
    {
        return $this->profile['host'];
    }

    public function headroomMib(): int
    {
        return $this->profile['headroom'];
    }

    /** What the containers may use together: the host size minus OS headroom. */
    public function availableMib(): int
    {
        return $this->hostMib() - $this->headroomMib();
    }

    /** @return array<string, int> service => MiB */
    public function limits(): array
    {
        return $this->profile['limits'];
    }

    /** The largest sum of limits any mode can render: everything, with edge and tunnel counted once (R61). */
    public function worstCaseMib(): int
    {
        $limits = $this->limits();

        return array_sum($limits) - min($limits['edge'], $limits['tunnel']);
    }

    public function has(string $service): bool
    {
        return array_key_exists($service, $this->limits());
    }

    public function limitMib(string $service): int
    {
        return $this->limits()[$service]
            ?? throw new InstanceException("The service [{$service}] has no memory budget; add it to ResourceBudget before rendering it.", ExitCode::InternalError, 'missing_memory_budget');
    }

    /** Compose `mem_limit` value, e.g. "384m". */
    public function memoryLimit(string $service): string
    {
        return $this->limitMib($service).'m';
    }

    /** Horizon processes of the default supervisor (HORIZON_MAX_PROCESSES, config/horizon.php). */
    public function horizonProcesses(): int
    {
        return $this->profile['horizon'];
    }

    /** Report exports stay on one process at every size (ADR 0033): large exports run one after another. */
    public function horizonExportsProcesses(): int
    {
        return 1;
    }

    /** FrankenPHP `num_threads` (a fixed pool; max_threads defaults to it). */
    public function frankenphpThreads(): int
    {
        return $this->profile['threads'];
    }

    /** CHROMIUM_MAX_CONCURRENCY; Chromium supports at most 6. */
    public function gotenbergConcurrency(): int
    {
        return $this->profile['chromium'];
    }

    /** CHROMIUM_MAX_QUEUE_SIZE: requests beyond it are refused instead of piling up in memory. */
    public function gotenbergQueueSize(): int
    {
        return self::GOTENBERG_QUEUE_PER_SLOT * $this->gotenbergConcurrency();
    }

    /**
     * Small-host Postgres settings derived from its limit: a quarter for shared buffers, an eighth for maintenance.
     *
     * @return array<string, string>
     */
    public function postgresSettings(): array
    {
        $limit = $this->limitMib('postgres');

        return [
            'shared_buffers' => intdiv($limit, 4).'MB',
            'effective_cache_size' => intdiv($limit * 3, 4).'MB',
            'maintenance_work_mem' => intdiv($limit, 8).'MB',
            'work_mem' => max(2, intdiv($limit, 64)).'MB',
            'max_connections' => (string) $this->profile['pg_connections'],
        ];
    }

    /** /dev/shm for parallel query: half the limit, a tmpfs that counts against it only when used. */
    public function postgresShmSize(): string
    {
        return intdiv($this->limitMib('postgres'), 2).'m';
    }

    /** Valkey `maxmemory`: three quarters of its limit, leaving room for the process and fork-on-save. */
    public function valkeyMaxMemory(): string
    {
        return intdiv($this->limitMib('redis') * 3, 4).'mb';
    }

    /** Mailpit MP_MAX_MESSAGES: the catcher prunes the oldest messages beyond it. */
    public function mailpitMaxMessages(): int
    {
        return $this->profile['mail_messages'];
    }
}
