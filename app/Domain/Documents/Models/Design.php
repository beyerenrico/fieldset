<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\DesignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A document design (BUILD_PROMPT §8.5): mutable draft settings plus N immutable versions, selected
 * by forms through `settings.document.design` (its key).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $key
 * @property string $name
 * @property bool $is_default
 * @property array<string, mixed> $settings
 * @property int|null $current_version_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property-read DesignVersion|null $currentVersion
 */
#[UseFactory(DesignFactory::class)]
#[Fillable(['key', 'name', 'is_default', 'settings', 'created_by', 'updated_by'])]
class Design extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<DesignFactory> */
    use HasFactory;

    protected $attributes = ['is_default' => false, 'current_version_id' => null];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_default' => 'boolean'];
    }

    public function draftSettings(): DesignSettings
    {
        return DesignSettings::fromArray($this->settings);
    }

    /** @return HasMany<DesignVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DesignVersion::class)->orderBy('version');
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'current_version_id');
    }

    public function latestVersionNumber(): int
    {
        return (int) $this->versions()->max('version');
    }
}
