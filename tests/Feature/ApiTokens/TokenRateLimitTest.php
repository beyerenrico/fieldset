<?php

declare(strict_types=1);

use App\Domain\Identity\Tokens\ApiRateLimitKey;
use App\Domain\Identity\Tokens\TokenAbility as A;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Tests\Feature\ApiTokens\Support\TokenApi;
use Tests\Support\FieldAppScenario;

beforeEach(function (): void {
    $this->s = FieldAppScenario::make();
});

it('keys the API rate limit by token, then user, then IP', function (): void {
    $plain = TokenApi::issue($this->s->owner, $this->s->org, [A::FormsRead]);
    $token = PersonalAccessToken::findToken($plain);

    $tokenRequest = Request::create('/api/v1/forms');
    $tokenRequest->setUserResolver(fn () => $this->s->owner->withAccessToken($token));
    $sessionRequest = Request::create('/api/v1/forms');
    $sessionRequest->setUserResolver(fn () => $this->s->worker->withAccessToken(new TransientToken));
    $guestRequest = Request::create('/api/v1/forms', server: ['REMOTE_ADDR' => '203.0.113.9']);

    expect(ApiRateLimitKey::for($tokenRequest))->toBe('token:'.$token->id)
        ->and(ApiRateLimitKey::for($sessionRequest))->toBe('user:'.$this->s->worker->id)
        ->and(ApiRateLimitKey::for($guestRequest))->toBe('ip:203.0.113.9');
});

it('rate-limits each token separately from the user and the other tokens', function (): void {
    config(['app.api_rate_limit' => 2]);
    $first = TokenApi::issue($this->s->owner, $this->s->org, [A::FormsRead], name: 'ERP');
    $second = TokenApi::issue($this->s->owner, $this->s->org, [A::FormsRead], name: 'BI');

    $this->withHeaders(TokenApi::headers($first))->getJson('/api/v1/forms')->assertOk();
    $this->withHeaders(TokenApi::headers($first))->getJson('/api/v1/forms')->assertOk();
    $this->withHeaders(TokenApi::headers($first))->getJson('/api/v1/forms')
        ->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');

    $this->withHeaders(TokenApi::headers($second))->getJson('/api/v1/forms')->assertOk();
    app('auth')->forgetGuards();
    $this->withHeaders(apiHeaders($this->s->owner, $this->s->org))->getJson('/api/v1/forms')->assertOk();
});
