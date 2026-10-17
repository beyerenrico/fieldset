<?php

declare(strict_types=1);

namespace Tests\Feature\ApiTokens\Support;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tokens\Actions\CreateApiToken;
use App\Domain\Identity\Tokens\TokenAbility;
use Carbon\CarbonInterface;

/** Issues real integration tokens and builds bearer-request headers for the API token tests (ADR 0043). */
final class TokenApi
{
    /** @param  list<TokenAbility>  $abilities */
    public static function issue(User $user, Organization $organization, array $abilities, ?CarbonInterface $expiresAt = null, string $name = 'ERP'): string
    {
        $values = array_map(static fn (TokenAbility $ability): string => $ability->value, $abilities);

        return app(CreateApiToken::class)->handle($user, $organization, $name, $values, $expiresAt)->plainTextToken;
    }

    /** @return list<TokenAbility> every ability except the given ones */
    public static function allExcept(TokenAbility ...$except): array
    {
        return array_values(array_filter(TokenAbility::cases(), static fn (TokenAbility $a): bool => ! in_array($a, $except, true)));
    }

    /**
     * Headers of a bearer request. The guards are forgotten first: the test application keeps the user the
     * sanctum guard resolved for an earlier request, so every request must authenticate afresh.
     *
     * @return array<string, string>
     */
    public static function headers(string $plainTextToken, ?Organization $organization = null): array
    {
        app('auth')->forgetGuards();

        $headers = ['Authorization' => 'Bearer '.$plainTextToken, 'Accept' => 'application/json'];
        if ($organization instanceof Organization) {
            $headers['X-Organization-Id'] = (string) $organization->id;
        }

        return $headers;
    }
}
