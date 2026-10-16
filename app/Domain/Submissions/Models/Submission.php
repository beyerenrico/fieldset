<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Models;

use App\Domain\Forms\Casts\JsonTree;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Submissions\Enums\SubmissionStatus;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * One filled-in instance of a form version (§2.4). Primary key is the client-generated UUID so
 * offline drafts upsert idempotently (§9.2). Status changes only through TransitionSubmission.
 *
 * @property string $uuid
 * @property int $organization_id
 * @property int $form_id
 * @property int $form_version_id
 * @property SubmissionStatus $status
 * @property stdClass $data
 * @property int $revision
 * @property string|null $number
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property stdClass|null $device
 * @property stdClass|null $geo
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Form $form
 * @property-read FormVersion $formVersion
 */
#[UseFactory(SubmissionFactory::class)]
class Submission extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['uuid', 'form_id', 'form_version_id', 'data', 'revision', 'created_by', 'updated_by', 'device', 'geo'];

    protected $attributes = [
        'status' => 'draft', 'revision' => 1, 'number' => null, 'submitted_by' => null, 'submitted_at' => null,
        'device' => null, 'geo' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'data' => JsonTree::class,
            'device' => JsonTree::class,
            'geo' => JsonTree::class,
            'revision' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'submission_uuid', 'uuid');
    }

    /** @return HasMany<SubmissionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SubmissionEvent::class, 'submission_uuid', 'uuid')->orderBy('id');
    }
}
