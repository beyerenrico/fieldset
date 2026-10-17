<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Single entry point for explicit audit events (auth, lifecycle, exports, settings).
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(string $event, ?Model $subject = null, array $properties = [], ?Model $causer = null): ?Activity
    {
        $logger = activity('audit')->event($event)->withProperties($properties);

        if ($subject instanceof Model) {
            $logger->performedOn($subject);
        }

        if ($causer instanceof Model) {
            $logger->causedBy($causer);
        }

        return $logger->log($event);
    }

    /**
     * An entry by the system (scheduled jobs such as retention and pruning): never attributed to a user that
     * happens to be signed in while the job runs synchronously.
     *
     * @param  array<string, mixed>  $properties
     */
    public function system(string $event, ?Model $subject = null, array $properties = []): ?Activity
    {
        $logger = activity('audit')->event($event)->withProperties($properties)->causedByAnonymous();
        if ($subject instanceof Model) {
            $logger->performedOn($subject);
        }

        return $logger->log($event);
    }

    /**
     * An entry by the instance owner at the host's command line (`fsa`, KTD21/KTD26): actor type `cli`, no
     * causer, and the host UID of whoever ran the command in `host_uid`.
     *
     * @param  array<string, mixed>  $properties
     */
    public function cli(string $event, ?Model $subject = null, array $properties = [], ?int $hostUid = null): ?Activity
    {
        $logger = activity('audit')->event($event)->withProperties([...$properties, 'host_uid' => $hostUid])->causedByAnonymous()
            ->tap(static fn (Model $activity): Model => $activity->setAttribute('actor_type', EnrichActivity::CLI));
        if ($subject instanceof Model) {
            $logger->performedOn($subject);
        }

        return $logger->log($event);
    }
}
