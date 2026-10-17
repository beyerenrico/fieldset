<?php

declare(strict_types=1);

namespace App\Domain\Platform\Health\Checks;

use App\Domain\Platform\Health\HealthCheck;
use App\Domain\Platform\Health\HealthResult;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

/**
 * Queue workers through Horizon's own repositories (§11 Reliability): supervisor status, pending and recently
 * failed jobs, the length of every queue, and the failed_jobs table. Inactive Horizon fails; paused or failed jobs warn.
 */
final readonly class QueueCheck implements HealthCheck
{
    public function __construct(
        private MasterSupervisorRepository $masters,
        private JobRepository $jobs,
        private WorkloadRepository $workload,
    ) {}

    public function key(): string
    {
        return 'queue';
    }

    public function run(): HealthResult
    {
        $status = $this->supervisorStatus();
        $failed = (int) $this->jobs->countRecentlyFailed();
        $details = [
            (string) __('platform.health.queue.status') => (string) __('platform.health.queue.statuses.'.$status),
            (string) __('platform.health.queue.pending') => (int) $this->jobs->countPending(),
            (string) __('platform.health.queue.recently_failed') => $failed,
            ...$this->failedTable(),
            ...$this->queueLengths(),
        ];

        return match (true) {
            $status === 'inactive' => HealthResult::fail($this->key(), (string) __('platform.health.queue.inactive'), $details),
            $status === 'paused' => HealthResult::warn($this->key(), (string) __('platform.health.queue.paused'), $details),
            $failed > 0 => HealthResult::warn($this->key(), trans_choice('platform.health.queue.failures', $failed, ['count' => $failed]), $details),
            default => HealthResult::ok($this->key(), (string) __('platform.health.queue.running'), $details),
        };
    }

    /** @return 'running'|'paused'|'inactive' */
    private function supervisorStatus(): string
    {
        $masters = collect($this->masters->all());
        if ($masters->isEmpty()) {
            return 'inactive';
        }

        return $masters->contains(fn (mixed $master): bool => is_object($master) && ($master->status ?? null) === 'paused') ? 'paused' : 'running';
    }

    /** @return array<string, int> */
    private function failedTable(): array
    {
        $failer = app('queue.failer');

        return $failer instanceof CountableFailedJobProvider
            ? [(string) __('platform.health.queue.failed_table') => $failer->count()]
            : [];
    }

    /** @return array<string, int> */
    private function queueLengths(): array
    {
        $lengths = [];
        foreach ($this->workload->get() as $queue) {
            if (is_array($queue) && isset($queue['name'])) {
                $lengths[(string) __('platform.health.queue.length', ['queue' => (string) $queue['name']])] = (int) ($queue['length'] ?? 0);
            }
        }

        return $lengths;
    }
}
