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
}
