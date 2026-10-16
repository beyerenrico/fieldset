<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\DesignVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable published snapshot of a design (BUILD_PROMPT §8.5, §8.10). The model and a database
 * trigger allow exactly one update: retiring (retired_at NULL → value).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $design_id
 * @property int $version
 * @property array<string, mixed> $settings
 * @property string $checksum
 * @property string $changelog
 * @property int|null $published_by
 * @property Carbon $published_at
 * @property Carbon|null $retired_at
 * @property-read Design $design
 */
#[UseFactory(DesignVersionFactory::class)]
#[Fillable(['design_id', 'version', 'settings', 'checksum', 'changelog', 'published_by', 'published_at'])]
#[WithoutTimestamps]
class DesignVersion extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<DesignVersionFactory> */
    use HasFactory;

    protected $attributes = ['retired_at' => null, 'published_by' => null];

    protected function casts(): array
    {
        return ['settings' => 'array', 'published_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $version): void {
            $onlyRetiring = array_keys($version->getDirty()) === ['retired_at']
                && $version->getOriginal('retired_at') === null;

            if (! $onlyRetiring) {
                throw new LogicException('Design versions are immutable; only retiring is allowed.');
            }
        });

        static::deleting(static fn (): never => throw new LogicException('Design versions are immutable.'));
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    public function designSettings(): DesignSettings
    {
        return DesignSettings::fromArray($this->settings);
    }

    /** @return BelongsTo<Design, $this> */
    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
