<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Sso\Enums\IdentityProviderStatus;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\SsoClient;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\IdentityProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An organization's OIDC or SAML identity provider (BUILD_PROMPT §8.7, ADR 0036); one per organization in v1.
 * `config` (flavor, issuer, tenant, client id, scopes, claim/attribute names, SAML metadata) and the
 * write-only `client_secret` are encrypted at rest and never serialized or audited. The uuid is the public id
 * in redirect/callback/ACS URLs, so renaming an organization never breaks a registered redirect URI.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property IdentityProviderType $type
 * @property string $name
 * @property IdentityProviderStatus $status
 * @property array<string, mixed> $config
 * @property string|null $client_secret
 * @property bool $jit_enabled
 * @property int|null $default_role_id
 * @property int|null $default_group_id
 * @property bool $enforce_sso
 * @property int|null $break_glass_user_id
 * @property bool $force_reauth
 * @property string|null $last_error
 * @property Carbon|null $last_error_at
 * @property Carbon|null $last_success_at
 * @property-read Organization $organization
 * @property-read Role|null $defaultRole
 * @property-read Group|null $defaultGroup
 * @property-read User|null $breakGlassUser
 */
#[UseFactory(IdentityProviderFactory::class)]
#[Fillable(['type', 'name', 'config', 'jit_enabled', 'default_role_id', 'default_group_id', 'force_reauth'])]
#[Hidden(['config', 'client_secret'])]
#[RouteKey('uuid')]
class IdentityProvider extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<IdentityProviderFactory> */
    use HasFactory;

    use LogsActivity;

    protected $attributes = [
        'status' => 'draft', 'client_secret' => null, 'jit_enabled' => false, 'default_role_id' => null,
        'default_group_id' => null, 'enforce_sso' => false, 'break_glass_user_id' => null, 'force_reauth' => false,
        'last_error' => null, 'last_error_at' => null, 'last_success_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'type' => IdentityProviderType::class,
            'status' => IdentityProviderStatus::class,
            'config' => 'encrypted:array',
            'client_secret' => 'encrypted',
            'jit_enabled' => 'boolean',
            'enforce_sso' => 'boolean',
            'force_reauth' => 'boolean',
            'last_error_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $provider): void {
            $attributes = $provider->getAttributes();
            if (($attributes['uuid'] ?? null) === null) {
                $provider->setAttribute('uuid', (string) Str::uuid());
            }
            if (! array_key_exists('config', $attributes)) {
                $provider->setAttribute('config', []);
            }
        });
    }

    /**
     * SSO routes run before any tenant is known (the tenant scope would throw): resolve by uuid across
     * organizations. Non-uuid values are a 404, never a database error.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            return null;
        }

        return static::query()->withoutTenant()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    /** @return BelongsTo<Role, $this> */
    public function defaultRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'default_role_id');
    }

    /** @return BelongsTo<Group, $this> */
    public function defaultGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'default_group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function breakGlassUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'break_glass_user_id');
    }

    /** @return HasMany<IdentityProviderMapping, $this> */
    public function mappings(): HasMany
    {
        return $this->hasMany(IdentityProviderMapping::class);
    }

    /** @return HasMany<ExternalIdentity, $this> */
    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    public function isActive(): bool
    {
        return $this->status === IdentityProviderStatus::Active;
    }

    public function hasClientSecret(): bool
    {
        return filled($this->client_secret);
    }

    /** Start of a login: the return target is the client enum, never a URL from the request. */
    public function redirectUrl(SsoClient $c, ?string $loginHint = null, bool $test = false): string
    {
        return route('sso.redirect', array_filter([
            'provider' => $this->uuid,
            'client' => $c->value,
            'login_hint' => $loginHint,
            'test' => $test ? 1 : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /** OIDC redirect URI to register at the IdP. */
    public function callbackUrl(): string
    {
        return route('sso.callback', ['provider' => $this->uuid]);
    }

    /** SAML Assertion Consumer Service URL (HTTP-POST binding). */
    public function samlAcsUrl(): string
    {
        return route('sso.saml.acs', ['provider' => $this->uuid]);
    }

    /** SAML SP entity id: the SP metadata URL. */
    public function samlEntityId(): string
    {
        return route('sso.saml.metadata', ['provider' => $this->uuid]);
    }

    /**
     * Configuration changes only; config and client_secret are never audited, enforcement, status and
     * health bookkeeping have their own explicit events (sso.enforcement_changed, sso.provider_*).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'name', 'jit_enabled', 'default_role_id', 'default_group_id', 'force_reauth'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('audit');
    }
}
