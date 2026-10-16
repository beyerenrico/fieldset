<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Actions\LogActivityAction;

/**
 * Adds tenant, actor type, IP, user agent and request id to every audit entry and redacts secrets.
 */
final class EnrichActivity extends LogActivityAction
{
    protected function beforeActivityLogged(Model $activity): void
    {
        parent::beforeActivityLogged($activity);

        $activity->setAttribute('organization_id', $this->organizationId($activity));
        $activity->setAttribute('actor_type', $this->actorType($activity));
        $activity->setAttribute('request_id', Context::get('request_id'));

        if (! app()->runningInConsole() || app()->runningUnitTests()) {
            $request = request();
            $activity->setAttribute('ip_address', $request->ip());
            $activity->setAttribute('user_agent', mb_substr((string) $request->userAgent(), 0, 1000) ?: null);
        }

        foreach (['properties', 'attribute_changes'] as $column) {
            $value = $activity->getAttribute($column);
            if ($value instanceof Collection) {
                $activity->setAttribute($column, new Collection(Redactor::redact($this->masked($activity, $column, $value->all()))));
            } elseif (is_array($value)) {
                $activity->setAttribute($column, Redactor::redact($this->masked($activity, $column, $value)));
            }
        }
    }

    /**
     * Value masking of the subject (MasksAuditAttributes) for the attribute diff.
     *
     * @param  array<array-key, mixed>  $changes
     * @return array<array-key, mixed>
     */
    private function masked(Model $activity, string $column, array $changes): array
    {
        $subject = $activity->getRelationValue('subject');
        if ($column !== 'attribute_changes' || ! $subject instanceof MasksAuditAttributes) {
            return $changes;
        }
        foreach (['attributes', 'old'] as $key) {
            if (is_array($changes[$key] ?? null)) {
                $changes[$key] = $subject->maskAuditAttributes($changes[$key]);
            }
        }

        return $changes;
    }

    private function organizationId(Model $activity): ?int
    {
        $subject = $activity->getRelationValue('subject');

        if ($subject instanceof Organization) {
            return $subject->id;
        }

        $attributes = $subject instanceof Model ? $subject->getAttributes() : [];
        if (isset($attributes['organization_id'])) {
            return (int) $attributes['organization_id'];
        }

        return app(TenantContext::class)->id();
    }

    private function actorType(Model $activity): string
    {
        if ($activity->getAttribute('causer_id') === null) {
            return 'system';
        }

        $user = request()->user();

        return $user !== null && method_exists($user, 'currentAccessToken')
            && $user->currentAccessToken() instanceof PersonalAccessToken ? 'api_token' : 'user';
    }
}
