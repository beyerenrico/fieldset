<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tokens\Actions\CreateApiToken;
use App\Domain\Identity\Tokens\Actions\RevokeApiToken;
use App\Domain\Identity\Tokens\TokenAbility as A;
use App\Domain\Identity\Tokens\TokenExpiry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\ApiTokens\Support\TokenApi;
use Tests\Support\FieldAppScenario;

beforeEach(function (): void {
    $this->s = FieldAppScenario::make();
    clearAuditLog();
});

function tokenAudit(string $event): array
{
    return Activity::query()->where('event', $event)->orderBy('id')->get()->all();
}

it('creates a token bound to the user and the organization with its abilities and expiry', function (): void {
    $expires = now()->addDays(30)->startOfSecond();
    $new = app(CreateApiToken::class)->handle($this->s->owner, $this->s->org, ' ERP export ', ['submissions:read', 'forms:read'], $expires);

    $token = PersonalAccessToken::findToken($new->plainTextToken);
    expect($token)->not->toBeNull()
        ->and($token->name)->toBe('ERP export')
        ->and($token->abilities)->toBe(['forms:read', 'submissions:read'])
        ->and($token->getAttribute('organization_id'))->toBe($this->s->org->id)
        ->and($token->tokenable_id)->toBe($this->s->owner->id)
        ->and($token->expires_at?->equalTo($expires))->toBeTrue()
        ->and($token->getAttribute('first_used_at'))->toBeNull();
});

it('validates name, abilities and expiry', function (string $name, array $abilities, ?int $days, string $field): void {
    $expires = $days === null ? null : now()->addDays($days);

    expect(fn () => app(CreateApiToken::class)->handle($this->s->owner, $this->s->org, $name, $abilities, $expires))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($field));
})->with([
    'empty name' => ['  ', ['forms:read'], 30, 'name'],
    'long name' => [str_repeat('x', 101), ['forms:read'], 30, 'name'],
    'no abilities' => ['ERP', [], 30, 'abilities'],
    'unknown ability' => ['ERP', ['forms:read', 'users:write'], 30, 'abilities.1'],
    'wildcard' => ['ERP', ['*'], 30, 'abilities.0'],
    'past expiry' => ['ERP', ['forms:read'], -1, 'expires_at'],
]);

it('allows tokens without expiry and offers a configurable default', function (): void {
    $new = app(CreateApiToken::class)->handle($this->s->owner, $this->s->org, 'ERP', ['forms:read'], null);
    expect($new->accessToken->expires_at)->toBeNull()
        ->and(TokenExpiry::defaultDays())->toBe(90);

    config(['api_tokens.default_expiry_days' => 30]);
    expect(TokenExpiry::defaultDays())->toBe(30)
        ->and(TokenExpiry::options())->toHaveKeys(['30', 'never']);
});

it('lets only panel users of the organization create tokens', function (): void {
    $worker = $this->s->worker;   // Field Worker: no admin.access
    $stranger = ownerOf(makeOrganization(name: 'Fremd GmbH'));

    expect(fn () => TokenApi::issue($worker, $this->s->org, [A::FormsRead]))->toThrow(AuthorizationException::class)
        ->and(fn () => TokenApi::issue($stranger, $this->s->org, [A::FormsRead]))->toThrow(AuthorizationException::class)
        ->and(TokenApi::issue(memberOf($this->s->org, SystemRole::Viewer), $this->s->org, [A::FormsRead]))->toContain('|');
});

it('lets users revoke their own tokens and members.manage revoke any token of the organization', function (): void {
    $designer = memberOf($this->s->org, SystemRole::Designer);
    $reviewer = memberOf($this->s->org, SystemRole::Reviewer);
    $designerToken = PersonalAccessToken::findToken(TokenApi::issue($designer, $this->s->org, [A::FormsRead]));
    $ownerToken = PersonalAccessToken::findToken(TokenApi::issue($this->s->owner, $this->s->org, [A::FormsRead]));
    $foreignOwner = ownerOf(makeOrganization(name: 'Fremd GmbH'));

    expect(fn () => app(RevokeApiToken::class)->handle($ownerToken, $designer))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RevokeApiToken::class)->handle($designerToken, $reviewer))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RevokeApiToken::class)->handle($designerToken, $foreignOwner))->toThrow(AuthorizationException::class);

    app(RevokeApiToken::class)->handle($designerToken, $this->s->owner);
    app(RevokeApiToken::class)->handle($ownerToken, $this->s->owner);
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('audits creation and revocation without secrets', function (): void {
    $new = app(CreateApiToken::class)->handle($this->s->owner, $this->s->org, 'ERP', ['forms:read'], now()->addDays(90));
    app(RevokeApiToken::class)->handle($new->accessToken, $this->s->owner);

    [$created] = tokenAudit('api_token.created');
    [$revoked] = tokenAudit('api_token.revoked');
    expect($created->organization_id)->toBe($this->s->org->id)
        ->and((int) $created->causer_id)->toBe($this->s->owner->id)
        ->and((int) $created->subject_id)->toBe($new->accessToken->id)
        ->and($created->properties->get('name'))->toBe('ERP')
        ->and($created->properties->get('abilities'))->toBe(['forms:read'])
        ->and($created->properties->get('owner_id'))->toBe($this->s->owner->id)
        ->and($revoked->organization_id)->toBe($this->s->org->id)
        ->and($revoked->properties->get('name'))->toBe('ERP');

    [, $secret] = explode('|', $new->plainTextToken, 2);
    $log = json_encode(Activity::query()->get()->toArray());
    expect($log)->not->toContain($secret)->not->toContain(hash('sha256', $secret));
});

it('audits the first use of a token once, as the token actor', function (): void {
    $plain = TokenApi::issue($this->s->owner, $this->s->org, [A::FormsRead]);

    $this->withHeaders(TokenApi::headers($plain))->getJson('/api/v1/forms')->assertOk();
    $this->withHeaders(TokenApi::headers($plain))->getJson('/api/v1/forms')->assertOk();
    $this->withHeaders(TokenApi::headers($plain))->getJson('/api/v1/forms/0/versions/0')->assertNotFound();

    $entries = tokenAudit('api_token.first_used');
    $token = PersonalAccessToken::findToken($plain);
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_type)->toBe('api_token')
        ->and($entries[0]->organization_id)->toBe($this->s->org->id)
        ->and((int) $entries[0]->causer_id)->toBe($this->s->owner->id)
        ->and($token->getAttribute('first_used_at'))->not->toBeNull()
        ->and($token->last_used_at)->not->toBeNull();
});
