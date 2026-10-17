<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tokens\TokenAbility as A;
use App\Http\Middleware\Api\RequireTokenAbility;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\ApiTokens\Support\TokenApi;
use Tests\Support\FieldAppScenario;

/*
 * Every authenticated /api/v1 route and the abilities an integration token needs for it (ADR 0043).
 * An empty list means "Field App session only". The inventory test fails when a route is added without
 * a deliberate entry here.
 */
const TOKEN_ROUTE_ABILITIES = [
    'api.me' => [],
    'api.devices.store' => [],
    'api.notification-preferences.update' => [],
    'api.notifications.index' => ['notifications:read'],
    'api.notifications.read' => ['notifications:read'],
    'api.notification-preferences.show' => ['notifications:read'],
    'api.forms.index' => ['forms:read'],
    'api.forms.version' => ['forms:read'],
    'api.sync.manifest' => ['forms:read', 'tasks:read'],
    'api.submissions.index' => ['submissions:read'],
    'api.submissions.show' => ['submissions:read'],
    'api.attachments.show' => ['submissions:read'],
    'api.submissions.upsert' => ['submissions:write'],
    'api.submissions.submit' => ['submissions:write'],
    'api.attachments.store' => ['submissions:write'],
    'api.submissions.document' => ['documents:read'],
    'api.submissions.send' => ['submissions:write', 'documents:read'],
    'api.tasks.index' => ['tasks:read'],
    'api.tasks.complete' => ['tasks:write'],
];

/** @return array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, string>} method, uri, body, headers */
function tokenRouteRequest(string $route, FieldAppScenario $s): array
{
    $uuid = FieldAppScenario::uuid();
    $key = ['Idempotency-Key' => (string) Str::uuid()];

    return match ($route) {
        'api.me' => ['GET', '/api/v1/me', [], []],
        'api.devices.store' => ['POST', '/api/v1/devices', ['device_uuid' => $uuid, 'name' => 'Phone', 'platform' => 'android'], []],
        'api.notification-preferences.update' => ['PUT', '/api/v1/me/notification-preferences', ['events' => []], []],
        'api.notifications.index' => ['GET', '/api/v1/notifications', [], []],
        'api.notifications.read' => ['POST', '/api/v1/notifications/read', [], []],
        'api.notification-preferences.show' => ['GET', '/api/v1/me/notification-preferences', [], []],
        'api.forms.index' => ['GET', '/api/v1/forms', [], []],
        'api.forms.version' => ['GET', "/api/v1/forms/{$s->form->id}/versions/{$s->version->id}", [], []],
        'api.sync.manifest' => ['GET', '/api/v1/sync/manifest', [], []],
        'api.submissions.index' => ['GET', '/api/v1/submissions', [], []],
        'api.submissions.show' => ['GET', "/api/v1/submissions/{$uuid}", [], []],
        'api.attachments.show' => ['GET', "/api/v1/submissions/{$uuid}/attachments/".FieldAppScenario::uuid(), [], []],
        'api.submissions.upsert' => ['PUT', "/api/v1/submissions/{$uuid}", ['form_version_id' => $s->version->id, 'data' => ['customer_name' => 'ERP GmbH']], []],
        'api.submissions.submit' => ['POST', "/api/v1/submissions/{$uuid}/submit", ['revision' => 1], $key],
        'api.attachments.store' => ['POST', "/api/v1/submissions/{$uuid}/attachments", [], []],
        'api.submissions.document' => ['GET', "/api/v1/submissions/{$uuid}/document", [], []],
        'api.submissions.send' => ['POST', "/api/v1/submissions/{$uuid}/send", ['email' => 'kunde@example.com'], $key],
        'api.tasks.index' => ['GET', '/api/v1/tasks', [], []],
        'api.tasks.complete' => ['POST', '/api/v1/tasks/999999/complete', ['outcome' => 'approve'], $key],
    };
}

/** @return list<A> */
function abilitiesOf(string $route): array
{
    return array_map(A::from(...), TOKEN_ROUTE_ABILITIES[$route]);
}

beforeEach(function (): void {
    $this->s = FieldAppScenario::make();
});

it('declares the abilities of every authenticated API route', function (): void {
    $declared = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r): bool => in_array('auth:sanctum', $r->gatherMiddleware(), true))
        ->mapWithKeys(function (RoutingRoute $r): array {
            $abilities = collect($r->gatherMiddleware())
                ->filter(fn (mixed $m): bool => is_string($m) && Str::before($m, ':') === RequireTokenAbility::class)
                ->map(fn (string $m): array => str_contains($m, ':') ? explode(',', Str::after($m, ':')) : [])
                ->values()->all();

            return [(string) $r->getName() => $abilities];
        })->all();

    expect(array_keys($declared))->toEqualCanonicalizing(array_keys(TOKEN_ROUTE_ABILITIES));
    foreach (TOKEN_ROUTE_ABILITIES as $route => $abilities) {
        expect($declared[$route])->toBe([$abilities], "Route {$route} must declare exactly one ability check");
    }
});

it('lets a token through with exactly the abilities of the route', function (string $route): void {
    $plain = TokenApi::issue($this->s->owner, $this->s->org, abilitiesOf($route));
    [$method, $uri, $body, $headers] = tokenRouteRequest($route, $this->s);

    $response = $this->withHeaders([...TokenApi::headers($plain), ...$headers])->json($method, $uri, $body);

    expect($response->json('error.code'))->not->toBeIn(['insufficient_ability', 'session_required', 'organization_forbidden', 'unauthenticated'])
        ->and($response->status())->toBeLessThan(500);
})->with(array_keys(array_filter(TOKEN_ROUTE_ABILITIES)));

it('refuses a token that lacks one of the abilities of the route', function (string $route): void {
    foreach (abilitiesOf($route) as $missing) {
        $plain = TokenApi::issue($this->s->owner, $this->s->org, TokenApi::allExcept($missing));
        [$method, $uri, $body, $headers] = tokenRouteRequest($route, $this->s);

        $this->withHeaders([...TokenApi::headers($plain), ...$headers])->json($method, $uri, $body)
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'insufficient_ability')
            ->assertJsonPath('error.details.missing', [$missing->value]);
    }
})->with(array_keys(array_filter(TOKEN_ROUTE_ABILITIES)));

it('keeps Field App routes for the session only', function (string $route): void {
    $plain = TokenApi::issue($this->s->owner, $this->s->org, A::cases());
    [$method, $uri, $body, $headers] = tokenRouteRequest($route, $this->s);

    $this->withHeaders([...TokenApi::headers($plain), ...$headers])->json($method, $uri, $body)
        ->assertForbidden()->assertJsonPath('error.code', 'session_required');
})->with(array_keys(array_filter(TOKEN_ROUTE_ABILITIES, static fn (array $a): bool => $a === [])));

it('reads forms and creates and reads a draft with matching abilities', function (): void {
    $plain = TokenApi::issue($this->s->owner, $this->s->org, [A::FormsRead, A::SubmissionsRead, A::SubmissionsWrite]);
    $uuid = FieldAppScenario::uuid();

    $this->withHeaders(TokenApi::headers($plain, $this->s->org))->getJson('/api/v1/forms')
        ->assertOk()->assertJsonPath('data.0.id', $this->s->form->id);
    $this->withHeaders(TokenApi::headers($plain, $this->s->org))
        ->putJson("/api/v1/submissions/{$uuid}", ['form_version_id' => $this->s->version->id, 'data' => ['customer_name' => 'ERP GmbH']])
        ->assertCreated()->assertJsonPath('data.submission.revision', 1);
    $this->withHeaders(TokenApi::headers($plain, $this->s->org))->getJson("/api/v1/submissions/{$uuid}")
        ->assertOk()->assertJsonPath('data.submission.uuid', $uuid);
});

it('never lets a token exceed the role permissions of its user', function (): void {
    $viewer = memberOf($this->s->org, SystemRole::Viewer);   // may enter the panel, may not fill forms
    $plain = TokenApi::issue($viewer, $this->s->org, A::cases());

    $response = $this->withHeaders(TokenApi::headers($plain, $this->s->org))
        ->putJson('/api/v1/submissions/'.FieldAppScenario::uuid(), ['form_version_id' => $this->s->version->id, 'data' => ['customer_name' => 'ERP GmbH']]);

    expect($response->status())->toBeIn([403, 404])
        ->and($response->json('error.code'))->not->toBe('insufficient_ability');
    $this->withHeaders(TokenApi::headers($plain, $this->s->org))->getJson('/api/v1/forms')
        ->assertOk()->assertJsonCount(0, 'data');
});

it('does not honour the wildcard ability', function (): void {
    $token = $this->s->owner->createToken('legacy', ['*']);
    $token->accessToken->forceFill(['organization_id' => $this->s->org->id])->save();

    $this->withHeaders(TokenApi::headers($token->plainTextToken, $this->s->org))->getJson('/api/v1/forms')
        ->assertForbidden()->assertJsonPath('error.code', 'insufficient_ability');
});
