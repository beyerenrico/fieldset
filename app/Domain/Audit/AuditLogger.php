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
}
