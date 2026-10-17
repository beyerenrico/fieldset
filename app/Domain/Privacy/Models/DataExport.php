<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Privacy\Enums\DataExportKind;
use App\Domain\Privacy\Enums\DataExportStatus;
use App\Domain\Privacy\Policies\DataExportPolicy;
use Database\Factories\DataExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A queued export (ADR 0039, the M5 export pattern of ADR 0033): a user's personal data as a ZIP of JSON
 * files, or the audit log as CSV. Only the requester downloads it, through a signed link that expires with
 * the file (`privacy.exports.keep_days`, default 7); `model:prune` then removes row and file.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $requested_by
 * @property int|null $subject_user_id
 * @property DataExportKind $kind
 * @property DataExportStatus $status
 * @property array<string, mixed> $options
 * @property string $disk
 * @property string|null $path
 * @property string|null $file_name
 * @property int|null $file_size
 * @property int|null $rows
 * @property string|null $error
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property-read User|null $requester
 * @property-read User|null $subject
 */
#[UseFactory(DataExportFactory::class)]
#[UsePolicy(DataExportPolicy::class)]
#[Fillable(['requested_by', 'subject_user_id', 'kind', 'status', 'options', 'disk'])]
class DataExport extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<DataExportFactory> */
    use HasFactory;

    use Prunable;

    protected $attributes = [
        'status' => 'pending', 'subject_user_id' => null, 'path' => null, 'file_name' => null, 'file_size' => null,
        'rows' => null, 'error' => null, 'completed_at' => null, 'expires_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'kind' => DataExportKind::class,
            'status' => DataExportStatus::class,
            'options' => 'array',
            'file_size' => 'integer',
            'rows' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public static function keepDays(): int
    {
        return max(1, (int) config('privacy.exports.keep_days', 7));
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function directory(): string
    {
        return 'data-exports/'.$this->organization_id.'/'.$this->id;
    }

    public function isReady(): bool
    {
        return $this->status === DataExportStatus::Completed && $this->path !== null
            && (! $this->expires_at instanceof Carbon || ! $this->expires_at->isPast());
    }

    /** Removes the stored file (pruning, erasure). */
    public function deleteFile(): void
    {
        Storage::disk($this->disk)->deleteDirectory($this->directory());
    }

    /**
     * Platform housekeeping across all organizations (`model:prune` runs without a tenant).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        // Expired files go; an export that never completed goes keep_days after it was requested.
        return static::query()->withoutTenant()->where(fn (Builder $q) => $q
            ->where(fn (Builder $done) => $done->whereNotNull('expires_at')->where('expires_at', '<', now()))
            ->orWhere(fn (Builder $open) => $open->whereNull('expires_at')->where('created_at', '<', now()->subDays(self::keepDays()))));
    }

    protected function pruning(): void
    {
        $this->deleteFile();
    }
}
