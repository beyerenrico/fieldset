<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Models;

use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo, file or signature image of a submission, keyed by a client-generated UUID so uploads are
 * idempotent (§9.2, ADR 0026). Stored on a private disk; served only through authorized routes.
 *
 * @property string $uuid
 * @property int $organization_id
 * @property string $submission_uuid
 * @property string $kind
 * @property string $element_key
 * @property string|null $original_name
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $disk
 * @property string $path
 * @property int|null $created_by
 */
#[UseFactory(AttachmentFactory::class)]
#[Fillable(['uuid', 'submission_uuid', 'kind', 'element_key', 'original_name', 'mime', 'size', 'sha256', 'disk', 'path', 'created_by'])]
#[WithoutIncrementing]
class Attachment extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    public const array KINDS = ['photo', 'file', 'signature'];

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $attributes = ['original_name' => null, 'created_by' => null];

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'submission_uuid', 'uuid');
    }
}
