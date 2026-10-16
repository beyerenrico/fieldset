<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Models;

use App\Domain\Dashboards\Policies\DashboardPinPolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Reports\Models\SavedReport;
use Database\Factories\DashboardPinFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved report pinned to one user's dashboard (§8.1 v1.0, ADR 0034). Written only by DashboardPins;
 * shown by PinnedReportsSource as one PinnedReportWidget per pin, ordered by `position`.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property int $saved_report_id
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read SavedReport|null $savedReport
 * @property-read User $user
 */
#[Fillable(['user_id', 'saved_report_id', 'position'])]
#[UseFactory(DashboardPinFactory::class)]
#[UsePolicy(DashboardPinPolicy::class)]
class DashboardPin extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<DashboardPinFactory> */
    use HasFactory;

    protected $attributes = ['position' => 0];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<SavedReport, $this> */
    public function savedReport(): BelongsTo
    {
        return $this->belongsTo(SavedReport::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
