<?php

declare(strict_types=1);

namespace App\Domain\Forms\Models;

use App\Domain\Forms\Casts\JsonTree;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\FormVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use stdClass;

/**
 * Immutable published snapshot of a form definition (BUILD_PROMPT §7.6). The model and a database
 * trigger allow exactly one update: retiring (retired_at NULL → value).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $form_id
 * @property int $version
 * @property stdClass $definition
 * @property stdClass $data_json_schema
 * @property string $checksum
 * @property int|null $design_version_id
 * @property string $changelog
 * @property int|null $published_by
 * @property Carbon $published_at
 * @property Carbon|null $retired_at
 * @property-read Form $form
 */
#[UseFactory(FormVersionFactory::class)]
#[Fillable([
    'form_id', 'version', 'definition', 'data_json_schema', 'checksum', 'design_version_id',
    'changelog', 'published_by', 'published_at',
])]
#[WithoutTimestamps]
class FormVersion extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<FormVersionFactory> */
    use HasFactory;

    protected $attributes = ['design_version_id' => null, 'retired_at' => null];

    protected function casts(): array
    {
        return [
            'definition' => JsonTree::class,
            'data_json_schema' => JsonTree::class,
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $version): void {
            $onlyRetiring = array_keys($version->getDirty()) === ['retired_at']
                && $version->getOriginal('retired_at') === null;

            if (! $onlyRetiring) {
                throw new LogicException('Form versions are immutable; only retiring is allowed.');
            }
        });

        static::deleting(static fn (): never => throw new LogicException('Form versions are immutable.'));
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
