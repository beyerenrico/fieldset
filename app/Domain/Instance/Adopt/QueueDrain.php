<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use Illuminate\Support\Sleep;

/**
 * Drains 1.0's queue before adopt stops it (KTD20 step 1, plan U19, ADR 0087). With app and scheduler stopped nothing
 * queues new jobs; Horizon keeps working while fsa counts the jobs in 1.0's Valkey, which 2.0 does not take over: a
 * one-off of the manifest's Valkey image on 1.0's default network runs one Lua script over the queue keys (any Redis
 * prefix): jobs waiting (lists, without Laravel's `:notify` lists), reserved (running) and delayed (scheduled for later).
 * Drained means nothing waits and nothing runs; delayed jobs never drain on their own and are reported. 1.0's Valkey has
 * no password in its compose.yaml; a probe that cannot count reports `unknown`, which stops adopt like jobs left unless
 * `--drop-jobs` (LegacyStack).
 */
final readonly class QueueDrain
{
    /** How long Horizon gets to finish the queue, and the pause between counts. */
    public const int SECONDS = 120;

    public const int INTERVAL = 3;

    public const string SCRIPT = <<<'LUA'
        local q, r, d = 0, 0, 0
        for _, k in ipairs(redis.call('KEYS', '*queues:*')) do
          local t = redis.call('TYPE', k)['ok']
          if t == 'list' and string.sub(k, -7) ~= ':notify' then q = q + redis.call('LLEN', k)
          elseif t == 'zset' and string.sub(k, -9) == ':reserved' then r = r + redis.call('ZCARD', k)
          elseif t == 'zset' and string.sub(k, -8) == ':delayed' then d = d + redis.call('ZCARD', k) end
        end
        return {q, r, d}
        LUA;

    public function __construct(private DockerGateway $docker) {}

    /** @return array{drained: bool|null, waiting: int|null, reserved: int|null, delayed: int|null, waited_seconds: int} */
    public function drain(LegacyInstall $legacy, string $image, string $uuid, int $redisDb = 0): array
    {
        $waited = 0;
        while (true) {
            $counts = $this->counts($legacy, $image, $uuid, $redisDb);
            if ($counts === null) {
                return ['drained' => null, 'waiting' => null, 'reserved' => null, 'delayed' => null, 'waited_seconds' => $waited];
            }
            [$waiting, $reserved, $delayed] = $counts;
            $drained = $waiting === 0 && $reserved === 0;
            if ($drained || $waited >= self::SECONDS) {
                return ['drained' => $drained, 'waiting' => $waiting, 'reserved' => $reserved, 'delayed' => $delayed, 'waited_seconds' => $waited];
            }
            Sleep::for(self::INTERVAL)->seconds();
            $waited += self::INTERVAL;
        }
    }

    /** @return array{0: int, 1: int, 2: int}|null waiting, reserved, delayed; null when Valkey cannot be asked */
    private function counts(LegacyInstall $legacy, string $image, string $uuid, int $redisDb): ?array
    {
        $outcome = $this->docker->runContainer(new ContainerSpec(
            image: $image,
            command: ['-h', 'redis', '-n', (string) $redisDb, '--no-auth-warning', '--raw', 'EVAL', self::SCRIPT, '0'],
            labels: Labels::forInstance($uuid, DatabaseProbe::ROLE),
            network: $legacy->network(),
            entrypoint: 'valkey-cli',
        ), null, 60.0);
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", trim($outcome->stdout))), static fn (string $line): bool => $line !== ''));

        if (! $outcome->successful() || count($lines) !== 3 || ! array_all($lines, static fn (string $line): bool => ctype_digit($line))) {
            return null;
        }

        return [(int) $lines[0], (int) $lines[1], (int) $lines[2]];
    }
}
