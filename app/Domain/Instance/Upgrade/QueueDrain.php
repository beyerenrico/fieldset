<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Output\ExitCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;

/**
 * Drains Horizon before an upgrade's safety backup (KTD19 step 4, plan U18, ADR 0086). The socket holder never opens
 * Valkey itself (KTD25): a socket-less one-off of the app service (`instance:queue-drain`) reports the queue sizes, and
 * fieldset polls it while the workers finish what is waiting. Drained means no job is pending or reserved on any Horizon
 * queue; delayed jobs (retries, scheduled notifications) stay in Valkey and run after the upgrade. For `--drop-jobs`,
 * `export()` reads the payloads still pending or reserved and `drop()` deletes them once fieldset saved them.
 */
final readonly class QueueDrain
{
    /** The bounded wait (the queue's own grace period is 100 s, the longest job 90 s). */
    public const int DRAIN_SECONDS = 300;

    public const int POLL_SECONDS = 5;

    private const float TIMEOUT = 300.0;

    public function __construct(private DockerGateway $docker) {}

    /**
     * @param  bool  $workers  the queue runs: without workers nothing drains, so the wait ends at the first look
     * @return array{drained: bool, pending: int, reserved: int, delayed: int, waited: int}
     */
    public function drain(ComposeProject $project, bool $workers): array
    {
        $started = CarbonImmutable::now();
        while (true) {
            $sizes = $this->sizes($project);
            $waited = (int) $started->diffInSeconds(CarbonImmutable::now(), true);
            if ($sizes['pending'] + $sizes['reserved'] === 0 || ! $workers || $waited + self::POLL_SECONDS > self::DRAIN_SECONDS) {
                return ['drained' => $sizes['pending'] + $sizes['reserved'] === 0, ...$sizes, 'waited' => $waited];
            }
            Sleep::for(self::POLL_SECONDS)->seconds();
        }
    }

    /**
     * The raw payloads of every job left pending or reserved (`--export`), nothing deleted yet.
     *
     * @return list<mixed>
     */
    public function export(ComposeProject $project): array
    {
        $payloads = $this->run($project, ['--export'])->data['payloads'] ?? null;

        return is_array($payloads) ? array_values($payloads) : [];
    }

    /** Deletes every job left pending or reserved (`--drop`), after export() was saved; returns how many went. */
    public function drop(ComposeProject $project): int
    {
        $dropped = $this->run($project, ['--drop'])->data['dropped'] ?? 0;

        return is_int($dropped) ? $dropped : 0;
    }

    /** @return array{pending: int, reserved: int, delayed: int} */
    private function sizes(ComposeProject $project): array
    {
        $data = $this->run($project, [])->data;
        $int = static fn (string $key): int => is_int($data[$key] ?? null) ? $data[$key] : 0;

        return ['pending' => $int('pending'), 'reserved' => $int('reserved'), 'delayed' => $int('delayed')];
    }

    /** @param  list<string>  $flags */
    private function run(ComposeProject $project, array $flags): OneOffResult
    {
        $result = OneOffResult::fromOutcome($this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'instance:queue-drain', ...$flags, '--json'], timeout: self::TIMEOUT));
        if (! $result->ok) {
            throw new InstanceException('The queue sizes could not be read: '.$result->errorMessage(), ExitCode::AbortedUnchanged, 'queue_unreadable', 'Check `fieldset logs redis`, then run `fieldset upgrade` again.', true, ['output' => $result->output]);
        }

        return $result;
    }
}
