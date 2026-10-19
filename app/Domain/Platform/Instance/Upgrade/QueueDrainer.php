<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Upgrade;

use Illuminate\Contracts\Queue\Factory;
use Illuminate\Queue\RedisQueue;
use InvalidArgumentException;

/**
 * The queue side of an upgrade's drain (KTD19 step 4, plan U18, ADR 0086), in a socket-less one-off: the sizes of every
 * Horizon queue (pending, reserved by a running worker, delayed), and for `--drop-jobs` an export of every job left in
 * them as the raw payloads Laravel stored, which are then deleted. fsa saves the export to a 0600 file beside the safety
 * backup; the payloads may carry personal data and never reach fsa's output.
 */
final readonly class QueueDrainer
{
    public function __construct(private Factory $queues) {}

    /** @return list<string> the queues Horizon's supervisors work (config/horizon.php) */
    public static function horizonQueues(): array
    {
        $names = [];
        foreach ((array) config('horizon.defaults', []) as $supervisor) {
            $queue = is_array($supervisor) ? ($supervisor['queue'] ?? []) : [];
            $names = [...$names, ...array_filter(is_array($queue) ? $queue : [$queue], is_string(...))];
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $names
     * @return array{pending: int, reserved: int, delayed: int, queues: array<string, array{pending: int, reserved: int, delayed: int}>}
     */
    public function sizes(array $names): array
    {
        $queue = $this->queue();
        $sizes = ['pending' => 0, 'reserved' => 0, 'delayed' => 0, 'queues' => []];
        foreach ($names as $name) {
            $one = ['pending' => (int) $queue->pendingSize($name), 'reserved' => (int) $queue->reservedSize($name), 'delayed' => (int) $queue->delayedSize($name)];
            $sizes['queues'][$name] = $one;
            foreach ($one as $key => $count) {
                $sizes[$key] += $count;
            }
        }

        return $sizes;
    }

    /**
     * The raw payloads of every job still pending or reserved (a worker took it but never finished: the workers are
     * stopped when fsa asks). Delayed jobs are not part of it: they wait in Valkey for their time.
     *
     * @param  list<string>  $names
     * @return list<array{queue: string, state: string, payload: string}>
     */
    public function export(array $names): array
    {
        $queue = $this->queue();
        $redis = $queue->getConnection();
        $payloads = [];
        foreach ($names as $name) {
            $key = $queue->getQueue($name);
            foreach (['pending' => (array) $redis->lrange($key, 0, -1), 'reserved' => (array) $redis->zrange($key.':reserved', 0, -1)] as $state => $jobs) {
                foreach ($jobs as $payload) {
                    $payloads[] = ['queue' => $name, 'state' => $state, 'payload' => (string) $payload];
                }
            }
        }

        return $payloads;
    }

    /**
     * Deletes every job still pending or reserved (after fsa saved export()'s payloads); delayed jobs stay.
     *
     * @param  list<string>  $names
     */
    public function drop(array $names): int
    {
        $queue = $this->queue();
        $redis = $queue->getConnection();
        $dropped = 0;
        foreach ($names as $name) {
            $key = $queue->getQueue($name);
            $dropped += (int) $redis->llen($key) + (int) $redis->zcard($key.':reserved');
            $redis->del($key, $key.':reserved');
        }

        return $dropped;
    }

    private function queue(): RedisQueue
    {
        $queue = $this->queues->connection('redis');
        if (! $queue instanceof RedisQueue) {
            throw new InvalidArgumentException('The upgrade drains Horizon, which needs the redis queue connection.');
        }

        return $queue;
    }
}
