<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Sso\Enums\DomainVerification;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\SsoDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An email domain claimed by an organization for SSO (ADR 0036). Many organizations may claim a domain;
 * a DNS TXT record proves ownership and only one organization can hold it verified (partial unique index).
 * `domain` is stored lower-case and in punycode; lookups are exact matches.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $domain
 * @property string $verification_token
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_checked_at
 * @property DomainVerification|null $last_check_result
 * @property int $failed_checks
 * @property int|null $created_by
 * @property-read Organization $organization
 */
#[UseFactory(SsoDomainFactory::class)]
#[Fillable(['domain', 'created_by'])]
class SsoDomain extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SsoDomainFactory> */
    use HasFactory;

    use LogsActivity;

    /** TXT record value prefix: `fieldset-verification=<token>`. */
    public const string TXT_VALUE_PREFIX = 'fieldset-verification=';

    protected $attributes = [
        'verified_at' => null, 'last_checked_at' => null, 'last_check_result' => null, 'failed_checks' => 0, 'created_by' => null,
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_check_result' => DomainVerification::class,
            'failed_checks' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $domain): void {
            if (($domain->getAttributes()['verification_token'] ?? null) === null) {
                $domain->setAttribute('verification_token', bin2hex(random_bytes(20)));
            }
        });
    }

    /**
     * The organization's claim that holds the domain verified, across organizations (discovery and the
     * password gate run before a tenant is known). Expects a normalized (lower-case, punycode) domain.
     */
    public static function verifiedOwnerOf(string $domain): ?self
    {
        return static::query()->withoutTenant()
            ->where('domain', Str::lower(trim($domain)))
            ->whereNotNull('verified_at')
            ->first();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** DNS name of the TXT record, e.g. `_fieldset-verification.example.com`. */
    public function txtName(): string
    {
        return config()->string('sso.dns.txt_prefix').'.'.$this->domain;
    }

    /** Expected TXT record value. */
    public function txtValue(): string
    {
        return self::TXT_VALUE_PREFIX.$this->verification_token;
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Claim and removal only; verification transitions are explicit events (sso.domain_*). */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['domain'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('audit');
    }
}
