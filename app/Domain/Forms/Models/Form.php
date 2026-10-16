<?php

declare(strict_types=1);

namespace App\Domain\Forms\Models;

use App\Domain\Forms\Casts\JsonTree;
use App\Domain\Forms\Enums\FormStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\FormFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use stdClass;

/**
 * A named, versioned form owned by an organization: one mutable draft and N immutable versions.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $key
 * @property string $name
 * @property string|null $category
 * @property FormStatus $status
 * @property stdClass $draft_definition
 * @property int|null $current_version_id
 * @property stdClass|null $import_report
 * @property int|null $created_by
 * @property int|null $updated_by
 */
#[UseFactory(FormFactory::class)]
#[Fillable(['key', 'name', 'category', 'status', 'draft_definition', 'import_report', 'created_by', 'updated_by'])]
class Form extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<FormFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'draft',
        'category' => null,
        'current_version_id' => null,
        'import_report' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => FormStatus::class,
            'draft_definition' => JsonTree::class,
            'import_report' => JsonTree::class,
        ];
    }

    /** @return HasMany<FormVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class)->orderBy('version');
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'current_version_id');
    }

    /** @return HasMany<FormAccess, $this> */
    public function accessGrants(): HasMany
    {
        return $this->hasMany(FormAccess::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function latestVersionNumber(): int
    {
        return (int) $this->versions()->max('version');
    }
}
