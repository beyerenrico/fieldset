<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Documents\Renditions\RenditionLinks;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Mail\Delivery\DeliverableDocument;
use App\Domain\Submissions\Models\Submission;
use Database\Factories\RenditionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An immutable PDF of a submission (BUILD_PROMPT §8.5). `input_hash` proves a re-render used the same
 * canonical input; `file_sha256` proves the stored file is unchanged. Re-rendering creates a new row.
 * Mailable as a DeliverableDocument (§8.8).
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string $submission_uuid
 * @property int $form_version_id
 * @property int $design_version_id
 * @property string $input_hash
 * @property string $file_sha256
 * @property string $renderer_version
 * @property int $page_count
 * @property string $disk
 * @property string $path
 * @property int $size
 * @property string $reason
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read Submission $submission
 * @property-read FormVersion $formVersion
 * @property-read DesignVersion $designVersion
 */
#[UseFactory(RenditionFactory::class)]
#[Fillable([
    'uuid', 'submission_uuid', 'form_version_id', 'design_version_id', 'input_hash', 'file_sha256',
    'renderer_version', 'page_count', 'disk', 'path', 'size', 'reason', 'created_by',
])]
#[RouteKey('uuid')]
class Rendition extends Model implements DeliverableDocument
{
    use BelongsToOrganization;

    /** @use HasFactory<RenditionFactory> */
    use HasFactory;

    public const string REASON_SUBMITTED = 'submitted';

    public const string REASON_RERENDER = 'rerender';

    public const UPDATED_AT = null;

    protected $attributes = ['created_by' => null];

    protected function casts(): array
    {
        return ['page_count' => 'integer', 'size' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Renditions are immutable.'));
        static::deleting(static fn (): never => throw new LogicException('Renditions are immutable.'));
    }

    public function deliveryDisk(): string
    {
        return $this->disk;
    }

    public function deliveryPath(): string
    {
        return $this->path;
    }

    public function deliveryFilename(): string
    {
        return RenditionLinks::fileName($this, $this->submission->number);
    }

    public function deliveryMimeType(): string
    {
        return 'application/pdf';
    }

    public function deliverySize(): int
    {
        return $this->size;
    }

    public function deliverySubmissionUuid(): ?string
    {
        return $this->submission_uuid;
    }

    public function deliveryRenditionId(): ?int
    {
        return $this->id;
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'submission_uuid', 'uuid');
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function designVersion(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class);
    }
}
