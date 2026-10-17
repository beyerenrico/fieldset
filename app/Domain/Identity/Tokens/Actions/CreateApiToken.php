<?php

declare(strict_types=1);

namespace App\Domain\Identity\Tokens\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tokens\ApiTokenAccess;
use App\Domain\Identity\Tokens\TokenAbility;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues an integration token for the user in one organization (ADR 0043). The plain text is returned once
 * (Sanctum stores only its SHA-256 hash); the audit entry carries name, abilities and expiry, never a secret.
 */
final readonly class CreateApiToken
{
    public const int NAME_MAX = 100;

    public function __construct(
        private ApiTokenAccess $access,
        private AuditLogger $audit,
    ) {}

    /** @param  array<array-key, mixed>  $abilities  ability values (TokenAbility) */
    public function handle(User $user, Organization $organization, string $name, array $abilities, ?DateTimeInterface $expiresAt): NewAccessToken
    {
        if (! $this->access->canCreate($user, $organization)) {
            throw new AuthorizationException(__('api_tokens.errors.create_forbidden'));
        }

        $name = trim($name);
        $this->validate($name, $abilities, $expiresAt);
        $abilities = TokenAbility::normalize($abilities);

        $token = DB::transaction(function () use ($user, $organization, $name, $abilities, $expiresAt): NewAccessToken {
            $token = $user->createToken($name, $abilities, $expiresAt);
            $token->accessToken->forceFill(['organization_id' => $organization->id])->save();

            return $token;
        });

        $this->audit->record('api_token.created', $token->accessToken, [
            'name' => $name,
            'abilities' => $abilities,
            'expires_at' => $expiresAt?->format(DATE_ATOM),
            'owner_id' => $user->id,
        ], $user);

        return $token;
    }

    /** @param  array<array-key, mixed>  $abilities */
    private function validate(string $name, array $abilities, ?DateTimeInterface $expiresAt): void
    {
        Validator::make(
            ['name' => $name, 'abilities' => $abilities, 'expires_at' => $expiresAt?->format(DATE_ATOM)],
            [
                'name' => ['required', 'string', 'max:'.self::NAME_MAX],
                'abilities' => ['required', 'array', 'list'],
                'abilities.*' => ['required', 'string', 'distinct', Rule::in(TokenAbility::values())],
                'expires_at' => ['nullable', 'date', 'after:now'],
            ],
            attributes: [
                'name' => __('api_tokens.form.name'),
                'abilities' => __('api_tokens.form.abilities'),
                'expires_at' => __('api_tokens.form.expires_in'),
            ],
        )->validate();
    }
}
