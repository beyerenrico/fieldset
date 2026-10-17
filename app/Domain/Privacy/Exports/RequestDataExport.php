<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Exports;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\Activity;
use App\Domain\Audit\Query\AuditLogFilters;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Privacy\Enums\DataExportKind;
use App\Domain\Privacy\Enums\DataExportStatus;
use App\Domain\Privacy\Models\DataExport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Queues a data export in the current organization (ADR 0039; the M5 pattern of ADR 0033): stores the
 * request, writes one audit entry and dispatches RunDataExport after commit. A user may export their own
 * data (self-service); Owners and Admins (members.manage) may export a member's data; audit log exports need
 * audit.view. At most one open export per requester and kind, and `privacy.exports.per_hour` requests.
 */
final readonly class RequestDataExport
{
    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    public function userData(User $requester, User $subject, string $locale, string $timezone): DataExport
    {
        $organizationId = $this->tenant->require();
        $self = $requester->is($subject);
        $allowed = $self
            ? $requester->isActive() && $requester->isMemberOf($organizationId)
            : $requester->isActive() && $requester->hasPermissionIn($organizationId, Permission::MembersManage)
                && Membership::query()->where('organization_id', $organizationId)->where('user_id', $subject->id)->exists();
        if (! $allowed) {
            throw new AuthorizationException;
        }

        return $this->queue($requester, DataExportKind::UserData, $subject->id, ['self_service' => $self, 'locale' => $locale, 'timezone' => $timezone],
            fn (DataExport $export): ?\Spatie\Activitylog\Contracts\Activity => $this->audit->record('privacy.data_export_requested', $subject, ['export_id' => $export->id, 'self_service' => $self], $requester));
    }

    public function auditLog(User $requester, AuditLogFilters $filters, string $locale, int $rows): DataExport
    {
        Gate::forUser($requester)->authorize('viewAny', Activity::class);

        return $this->queue($requester, DataExportKind::AuditLog, null, ['filters' => $filters->toArray(), 'locale' => $locale, 'timezone' => $filters->timezone],
            fn (DataExport $export): ?\Spatie\Activitylog\Contracts\Activity => $this->audit->record('audit.exported', $export, ['filters' => $filters->toArray(), 'rows' => $rows, 'queued' => true], $requester));
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  \Closure(DataExport): mixed  $audit
     */
    private function queue(User $requester, DataExportKind $kind, ?int $subjectId, array $options, \Closure $audit): DataExport
    {
        $this->throttle($requester, $kind);

        return DB::transaction(function () use ($requester, $kind, $subjectId, $options, $audit): DataExport {
            $export = DataExport::query()->create([
                'requested_by' => $requester->id, 'subject_user_id' => $subjectId, 'kind' => $kind,
                'status' => DataExportStatus::Pending, 'options' => $options, 'disk' => (string) config('privacy.exports.disk', 'local'),
            ]);
            $audit($export);
            dispatch(new RunDataExport($export->id, $export->organization_id));

            return $export;
        });
    }

    private function throttle(User $requester, DataExportKind $kind): void
    {
        $open = DataExport::query()->where('requested_by', $requester->id)->where('kind', $kind->value)
            ->whereIn('status', [DataExportStatus::Pending->value, DataExportStatus::Running->value])
            ->where('created_at', '>', now()->subHour())->exists();
        if ($open) {
            throw ValidationException::withMessages(['export' => __('privacy.exports.already_running')]);
        }

        $key = 'data-export:'.$requester->id;
        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('privacy.exports.per_hour', 10)))) {
            throw ValidationException::withMessages(['export' => __('privacy.exports.throttled', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)])]);
        }
        RateLimiter::hit($key, 3600);
    }
}
