<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Instance\Overview\InstanceProblems;
use App\Domain\Platform\Models\PlatformAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies the platform operators of failed or stale backups (R39, plan U20, ADR 0089), run hourly by the scheduler
 * (`instance:backup-alerts`). It raises the alerts `fieldset status` reports as `warn` and the instance page shows first
 * (BackupRuns, BackupAlerts), each once per incident: an alert is identified by its code and the run it is about (for
 * `backup_overdue` the last success), remembered for 30 days in the cache. A new failure, or the same alert again after a
 * success, is a new incident. Without an active operator nothing is remembered, so the first operator still hears of it.
 * Every active operator gets a mail in their locale; one that cannot be queued is logged and does not stop the others.
 * When no mail could be queued at all (queue or Valkey down), the incident is forgotten again, so the next run retries it.
 */
final readonly class BackupAlertNotifier
{
    public const string CACHE_PREFIX = 'platform:backup_alert:';

    public const int REMEMBER_DAYS = 30;

    public function __construct(private BackupRuns $runs, private Cache $cache) {}

    /** @return list<string> the alert codes notified now */
    public function notify(?CarbonImmutable $now = null): array
    {
        $alerts = $this->runs->summary($now)['alerts'];
        $operators = $alerts === [] ? [] : $this->operators();
        if ($operators === []) {
            return [];
        }
        $notified = [];
        foreach ($alerts as $alert) {
            $key = self::CACHE_PREFIX.hash('sha256', $alert['code'].'|'.($alert['run']['started_at'] ?? '-'));
            if (! $this->cache->add($key, true, now()->addDays(self::REMEMBER_DAYS))) {
                continue; // this incident was notified already
            }
            if ($this->send($operators, $alert) === 0) {
                $this->cache->forget($key);

                continue;
            }
            $notified[] = $alert['code'];
        }

        return $notified;
    }

    /**
     * @param  list<User>  $operators
     * @param  array{code: string, run: array<string, mixed>|null}  $alert
     * @return int the operators whose mail was queued
     */
    private function send(array $operators, array $alert): int
    {
        $run = $alert['run'];
        $queued = 0;
        foreach ($operators as $operator) {
            try {
                $operator->notify(new BackupAlertNotification(
                    $alert['code'],
                    is_string($run['reason'] ?? null) ? $run['reason'] : null,
                    is_int($run['missing_files'] ?? null) ? $run['missing_files'] : 0,
                    InstanceProblems::BACKUP[$alert['code']][1] ?? null,
                )->locale($operator->locale ?? (string) config('app.locale')));
                $queued++;
            } catch (Throwable $e) {
                Log::warning('Backup alert could not be sent to an operator.', ['alert' => $alert['code'], 'user_id' => $operator->id, 'exception' => $e::class]);
            }
        }

        return $queued;
    }

    /** @return list<User> the active platform operators, longest-standing first */
    private function operators(): array
    {
        $users = PlatformAdmin::query()->with('user')->orderBy('id')->get()->map(static fn (PlatformAdmin $grant): User => $grant->user)->all();

        return array_values(array_filter($users, static fn (User $user): bool => $user->isActive()));
    }
}
