<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Support\OrganizationPermissions;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use SensitiveParameter;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserStatus $status
 * @property string|null $locale
 * @property string|null $timezone
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $deactivated_at
 */
#[UseFactory(UserFactory::class)]
#[Fillable(['name', 'email', 'password', 'locale', 'timezone'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasDefaultTenant, HasTenants, MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use LogsActivity;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /** Defaults keep strict mode happy for freshly created models. */
    protected $attributes = [
        'status' => 'active',
        'locale' => null,
        'timezone' => null,
        'deactivated_at' => null,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'deactivated_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // MFA enrolment changes from either Filament or Fortify are audited once (§8.11).
        static::updated(static function (self $user): void {
            if ($user->wasChanged('two_factor_confirmed_at')) {
                app(AuditLogger::class)->record(
                    $user->two_factor_confirmed_at === null ? 'auth.mfa_disabled' : 'auth.mfa_enabled',
                    $user,
                );
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'status', 'locale', 'timezone', 'deactivated_at'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('audit');
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /** @return BelongsToMany<Organization, $this, Membership, 'membership'> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot(['id', 'status', 'default_group_id', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Organization, $this, Membership, 'membership'> */
    public function activeOrganizations(): BelongsToMany
    {
        return $this->organizations()->wherePivot('status', MembershipStatus::Active->value);
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return BelongsToMany<Group, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)->withPivot('organization_id')->withTimestamps();
    }

    public function isMemberOf(Organization|int $organization): bool
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        return $this->activeOrganizations()->whereKey($id)->exists();
    }

    public function hasPermissionIn(Organization|int $organization, Permission $permission): bool
    {
        return app(OrganizationPermissions::class)->has($this, $organization, $permission);
    }

    // --- Filament panel + tenancy -------------------------------------------------------------

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($panel->getId() !== 'admin') {
            return false;
        }

        // Users without any organization may reach the panel only to register one.
        $organizations = $this->activeOrganizations()->get();

        if ($organizations->isEmpty()) {
            return (bool) config('app.allow_registration');
        }

        return $organizations->contains(fn (Organization $org): bool => $this->hasPermissionIn($org, Permission::AdminAccess));
    }

    /** @return Collection<int, Organization> */
    public function getTenants(Panel $panel): Collection
    {
        return $this->activeOrganizations()->get()
            ->filter(fn (Organization $org): bool => $this->hasPermissionIn($org, Permission::AdminAccess))
            ->values();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Organization
            && $this->isActive()
            && $this->isMemberOf($tenant)
            && $this->hasPermissionIn($tenant, Permission::AdminAccess);
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->getTenants($panel)->first();
    }

    // --- One shared TOTP secret for Filament MFA and Fortify 2FA (ADR 0016) -------------------

    public function getAppAuthenticationSecret(): ?string
    {
        if ($this->two_factor_secret === null || $this->two_factor_confirmed_at === null) {
            return null;
        }

        return (string) Fortify::currentEncrypter()->decrypt($this->two_factor_secret);
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill($secret === null ? [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ] : [
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return array<string>|null */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->two_factor_recovery_codes === null ? null : $this->recoveryCodes();
    }

    /** @param  array<string>|null  $codes  plain codes (Fortify format), see SharedAppAuthentication */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill([
            'two_factor_recovery_codes' => $codes === null
                ? null
                : Fortify::currentEncrypter()->encrypt((string) json_encode(array_values($codes))),
        ])->save();
    }

    /**
     * A used recovery code is removed (not replaced), in both Fortify and Filament flows.
     *
     * @param  string  $code
     */
    public function replaceRecoveryCode($code): void
    {
        $remaining = array_values(array_filter(
            $this->recoveryCodes(),
            static fn (string $candidate): bool => ! hash_equals($candidate, (string) $code),
        ));

        $this->saveAppAuthenticationRecoveryCodes($remaining);
    }
}
