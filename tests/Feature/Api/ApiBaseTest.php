<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Http\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware(['api', 'auth:sanctum', 'organization'])->prefix('api/v1')->group(function (): void {
        Route::get('_probe', fn (): JsonResponse => ApiResponse::ok(['tenant' => app(TenantContext::class)->id()]));
        Route::post('_validate', fn (Request $r): JsonResponse => ApiResponse::ok($r->validate(['items.*.qty' => 'required|numeric'])));
    });
});

it('wraps /me in the envelope with organizations, roles and groups', function (): void {
    $org = makeOrganization();
    $user = memberOf($org, SystemRole::Designer, SystemRole::Reviewer);

    $this->withHeaders(apiHeaders($user, $org))->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('error', null)
        ->assertJsonPath('data.organizations.0.id', $org->id)
        ->assertJsonPath('data.organizations.0.roles', ['designer', 'reviewer'])
        ->assertJsonPath('data.organizations.0.groups', []);
});

it('returns 401 in the envelope when unauthenticated', function (): void {
    $this->getJson('/api/v1/me')->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('requires X-Organization-Id on tenant routes', function (): void {
    $org = makeOrganization();
    $headers = apiHeaders(ownerOf($org), $org);
    unset($headers['X-Organization-Id']);

    $this->withHeaders($headers)->getJson('/api/v1/_probe')
        ->assertStatus(400)->assertJsonPath('error.code', 'organization_required');
});

it('rejects organizations the user is not an active member of', function (): void {
    $orgA = makeOrganization(name: 'A');
    $orgB = makeOrganization(name: 'B');

    $this->withHeaders(apiHeaders(ownerOf($orgA), $orgB))->getJson('/api/v1/_probe')
        ->assertForbidden()->assertJsonPath('error.code', 'organization_forbidden');
});

it('sets the tenant context from the header', function (): void {
    $org = makeOrganization();

    $this->withHeaders(apiHeaders(ownerOf($org), $org))->getJson('/api/v1/_probe')
        ->assertOk()->assertJsonPath('data.tenant', $org->id);
});

it('keys validation errors by JSON Pointer with stable codes', function (): void {
    $org = makeOrganization();

    $this->withHeaders(apiHeaders(ownerOf($org), $org))
        ->postJson('/api/v1/_validate', ['items' => [['qty' => '1'], ['qty' => 'x'], []]])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.0.pointer', '/items/1/qty')
        ->assertJsonPath('error.details.0.code', 'numeric')
        ->assertJsonPath('error.details.1.pointer', '/items/2/qty')
        ->assertJsonPath('error.details.1.code', 'required');
});

it('converts dot paths to JSON pointers with escaping', function (): void {
    expect(ApiResponse::pointer('items.2.qty'))->toBe('/items/2/qty')
        ->and(ApiResponse::pointer('a/b.c~d'))->toBe('/a~1b/c~0d')
        ->and(ApiResponse::pointer(''))->toBe('');
});

it('echoes or assigns a request id', function (): void {
    $this->get('/up', ['X-Request-Id' => 'req-12345678'])->assertHeader('X-Request-Id', 'req-12345678');
    expect($this->get('/up')->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');
});
