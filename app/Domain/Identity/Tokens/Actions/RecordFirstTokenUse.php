<?php

declare(strict_types=1);

namespace App\Domain\Identity\Tokens\Actions;

use App\Domain\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Audits the first request of an integration token, exactly once (ADR 0043): a conditional update of
 * `first_used_at` picks the one request that wins, also when two first requests race. Later uses only move
 * Sanctum's `last_used_at`; they are not audited.
 */
final readonly class RecordFirstTokenUse
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(PersonalAccessToken $token): void
    {
        if ($token->getAttribute('first_used_at') !== null) {
            return;
        }

        $now = now();
        $won = PersonalAccessToken::query()->whereKey($token->getKey())->whereNull('first_used_at')
            ->update(['first_used_at' => $now]) === 1;
        $token->forceFill(['first_used_at' => $now])->syncOriginalAttribute('first_used_at');

        if (! $won) {
            return;
        }

        $owner = $token->tokenable;
        $this->audit->record('api_token.first_used', $token, [
            'name' => $token->name,
            'owner_id' => (int) $token->getAttribute('tokenable_id'),
        ], $owner instanceof Model ? $owner : null);
    }
}
