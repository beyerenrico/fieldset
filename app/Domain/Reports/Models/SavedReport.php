<?php

declare(strict_types=1);

namespace App\Domain\Reports\Models;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Reports\Enums\ReportSchedule;
use App\Domain\Reports\Enums\ReportVisibility;
use App\Domain\Reports\Filters\ReportFilters;
use App\Domain\Reports\Policies\SavedReportPolicy;
use Database\Factories\SavedReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved report (BUILD_PROMPT §8.4): form + filters + group-by + metric (count, sum or avg over a
 * numeric key), private or shared with the organization, optionally emailed weekly or monthly to members.
 * Results come from SavedReportQuery, always as the viewing user (their submission visibility).
 *
 * `group_by`: null (one total row), `status`, `submitter`, `version`, `day`, `week`, `month` (of
 * submitted_at, organization time zone) or `field:<key>` (a groupable top-level key).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $form_id
 * @property string $name
 * @property array<string, mixed> $filters
 * @property string|null $group_by
 * @property ReportMetric $metric
 * @property string|null $metric_key
 * @property ReportVisibility $visibility
 * @property ReportSchedule $schedule
 * @property list<int> $recipients
 * @property Carbon|null $last_sent_at
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Form $form
 * @property-read User|null $creator
 */
#[Fillable(['form_id', 'name', 'filters', 'group_by', 'metric', 'metric_key', 'visibility', 'schedule', 'recipients', 'created_by'])]
#[UseFactory(SavedReportFactory::class)]
#[UsePolicy(SavedReportPolicy::class)]
class SavedReport extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SavedReportFactory> */
    use HasFactory;

    public const array GROUP_COLUMNS = ['status', 'submitter', 'version', 'day', 'week', 'month'];

    protected $attributes = [
        'filters' => '{}', 'group_by' => null, 'metric' => 'count', 'metric_key' => null,
        'visibility' => 'private', 'schedule' => 'none', 'recipients' => '[]', 'last_sent_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'metric' => ReportMetric::class,
            'visibility' => ReportVisibility::class,
            'schedule' => ReportSchedule::class,
            'recipients' => 'array',
            'last_sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reportFilters(): ReportFilters
    {
        return ReportFilters::fromArray($this->filters);
    }

    /** @return list<int> */
    public function recipientIds(): array
    {
        return array_values(array_unique(array_map(intval(...), array_filter($this->recipients, is_numeric(...)))));
    }
}
