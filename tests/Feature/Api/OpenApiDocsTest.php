<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use Illuminate\Support\Facades\Gate;

it('generates an OpenAPI 3.1 document covering the v1 routes', function (): void {
    $org = makeOrganization();
    $this->actingAs(ownerOf($org));

    $doc = $this->getJson('/docs/api.json')->assertOk()->json();

    expect($doc['openapi'])->toStartWith('3.1')
        ->and($doc['info']['title'])->toBe('Fieldset API v1')
        ->and(array_keys($doc['paths']))->toContain('/me', '/forms', '/submissions/{uuid}', '/submissions/{uuid}/submit', '/auth/device-status');
});

it('restricts the docs to platform and organization admins', function (): void {
    $org = makeOrganization();

    expect(Gate::forUser(ownerOf($org))->allows('viewApiDocs'))->toBeTrue()
        ->and(Gate::forUser(memberOf($org, SystemRole::FieldWorker))->allows('viewApiDocs'))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewApiDocs'))->toBeFalse();
});
