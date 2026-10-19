<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\MembershipStatus;
use Database\Factories\OrganizationFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A tenant: one company (BUILD_PROMPT §2.4, §6.2).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $locale
 * @property string $timezone
 * @property array<string, mixed> $branding
 * @property array<string, mixed> $company_details
 * @property bool $is_demo the demo organization (DemoSeeder only, never mass-assigned; ADR 0081)
 */
#[UseFactory(OrganizationFactory::class)]
#[Fillable(['name', 'slug', 'locale', 'timezone', 'branding', 'company_details'])]
class Organization extends Model implements HasName
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected $attributes = [
        'branding' => '{}',
        'company_details' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'branding' => 'array',
            'company_details' => 'array',
            'is_demo' => 'boolean',
        ];
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    /** @return BelongsToMany<User, $this, Membership, 'membership'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot(['id', 'status', 'default_group_id', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<User, $this, Membership, 'membership'> */
    public function activeMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('status', MembershipStatus::Active->value);
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<Role, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /** @return HasMany<Group, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'slug', 'locale', 'timezone', 'branding', 'company_details'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('audit');
    }
}
