<?php

declare(strict_types=1);

namespace App\Domain\Reports\Models;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\Policies\ReportExportPolicy;
use Database\Factories\ReportExportFactory;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A queued export of the submission explorer (BUILD_PROMPT §8.4, ADR 0033). Reuses Filament's Export
 * model and `exports` table (processed/total/successful rows, file disk, completion) with tenancy, the
 * form, the format, the options the job needs and an expiry. Filament's own exporter and download route
 * are not used (the route is denied: Export has the deny-all InternalRecordPolicy): columns are per form,
 * so RunExport writes the file and ExportDownloadController serves it to the requesting user only. `file_name` is the download name including the extension. Files are
 * pruned (`model:prune`) reports.exports.keep_days after creation; downloads answer 410 after expiry.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $form_id
 * @property int $user_id
 * @property ExportFormat $format
 * @property ExportStatus $status
 * @property string|null $file_path
 * @property int|null $file_size
 * @property array<string, mixed> $options
 * @property string|null $error
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property-read Form|null $form
 */
#[Table(name: 'exports')]
#[UseFactory(ReportExportFactory::class)]
#[UsePolicy(ReportExportPolicy::class)]
class ReportExport extends Export
{
    use BelongsToOrganization;

    /** @use HasFactory<ReportExportFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'pending', 'processed_rows' => 0, 'successful_rows' => 0, 'error' => null];

    protected function casts(): array
    {
        return [
            'format' => ExportFormat::class,
            'status' => ExportStatus::class,
            'options' => 'array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'processed_rows' => 'integer',
            'total_rows' => 'integer',
            'successful_rows' => 'integer',
            'file_size' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function getFileDirectory(): string
    {
        return 'exports/'.$this->organization_id.'/'.$this->getKey();
    }

    public function isReady(): bool
    {
        return $this->status === ExportStatus::Completed && $this->file_path !== null && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at instanceof Carbon && $this->expires_at->isPast();
    }

    /**
     * Platform-level housekeeping across all organizations (`model:prune` runs without a tenant).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->withoutTenant()
            ->where('created_at', '<', now()->subDays(max(1, (int) config('reports.exports.keep_days', 7))));
    }

    protected function pruning(): void
    {
        $this->deleteFileDirectory();
    }
}
