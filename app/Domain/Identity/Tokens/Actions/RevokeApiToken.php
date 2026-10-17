<?php

declare(strict_types=1);

namespace App\Domain\Identity\Tokens\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tokens\ApiTokenAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Revokes (deletes) an integration token: its owner, or `members.manage` in the token's organization
 * (ADR 0043). The next request with the token answers 401.
 */
final readonly class RevokeApiToken
{
    public function __construct(
        private ApiTokenAccess $access,
        private AuditLogger $audit,
    ) {}

    public function handle(PersonalAccessToken $token, User $actor): void
    {
        if (! $this->access->canRevoke($actor, $token)) {
            throw new AuthorizationException(__('api_tokens.errors.revoke_forbidden'));
        }

        $this->audit->record('api_token.revoked', $token, [
            'name' => $token->name,
            'abilities' => $token->abilities,
            'owner_id' => (int) $token->getAttribute('tokenable_id'),
        ], $actor);

        $token->delete();
    }
}
