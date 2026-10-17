<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Domain\Platform\PlatformAdmins;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Auth\Notifications\ResetPassword;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        'password_known' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'deactivated_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password_known' => 'boolean',
        ];
    }

    /** Set while an action audits the password change itself (e.g. accepting an invitation). */
    private static bool $suppressPasswordAudit = false;

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function withoutPasswordAudit(\Closure $callback): mixed
    {
        self::$suppressPasswordAudit = true;

        try {
            return $callback();
        } finally {
            self::$suppressPasswordAudit = false;
        }
    }

    protected static function booted(): void
    {
        // Setting a password makes it known (ADR 0039), unless the same save marks it unknown (SSO, invitation).
        static::updating(static function (self $user): void {
            if ($user->isDirty('password') && ! $user->isDirty('password_known')) {
                $user->password_known = true;
            }
        });

        // MFA enrolment changes from either Filament or Fortify are audited once (§8.11).
        static::updated(static function (self $user): void {
            if ($user->wasChanged('password') && ! self::$suppressPasswordAudit) {
                app(AuditLogger::class)->record('auth.password_changed', $user);
            }

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

    /**
     * Accounts at organizations' identity providers (ADR 0036). Tenant-scoped: query inside the tenant.
     *
     * @return HasMany<ExternalIdentity, $this>
     */
    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    /**
     * Backstop for reset flows that use the broker's default notification (Fortify; ADR 0036, Enforcement):
     * no reset link for an email whose domain enforces SSO. Filament's reset page notifies directly and
     * needs its own check, like every flow that refuses earlier.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        try {
            app(PasswordAuthGate::class)->assertPasswordReset($this->email);
        } catch (SsoRequiredException) {
            app(AuditLogger::class)->record('auth.password_reset_blocked', $this, ['via' => 'notification']);

            return;
        }

        $this->notify(new ResetPassword($token));
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

        // The /platform operator panel (ADR 0041): a platform_admins grant, no organization needed.
        if ($panel->getId() === 'platform') {
            return app(PlatformAdmins::class)->check($this);
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
        // Re-read under a row lock so a code can never be used twice by concurrent requests.
        DB::transaction(function () use ($code): void {
            $fresh = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            $codes = $fresh->two_factor_recovery_codes === null ? [] : $fresh->recoveryCodes();
            $remaining = array_values(array_filter(
                $codes,
                static fn (string $candidate): bool => ! hash_equals($candidate, (string) $code),
            ));

            if (count($remaining) === count($codes)) {
                throw ValidationException::withMessages(['recovery_code' => __('auth.failed')]);
            }

            $fresh->saveAppAuthenticationRecoveryCodes($remaining);
            $this->setRawAttributes($fresh->getAttributes(), true);
        });

        app(AuditLogger::class)->record('auth.recovery_code_used', $this, [], $this);
    }
}
