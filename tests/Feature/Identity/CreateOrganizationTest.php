<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;

it('creates an organization with seeded roles and the creator as owner', function (): void {
    $owner = User::factory()->create();
    $org = makeOrganization($owner, 'Müller Haustechnik');

    expect($org->slug)->toBe('muller-haustechnik')
        ->and(Role::query()->where('organization_id', $org->id)->pluck('name')->sort()->values()->all())
        ->toBe(collect(SystemRole::cases())->map->value->sort()->values()->all())
        ->and($owner->isMemberOf($org))->toBeTrue()
        ->and($org->memberships()->first()->status)->toBe(MembershipStatus::Active);
});

it('generates unique slugs', function (): void {
    $a = makeOrganization(name: 'Acme');
    $b = makeOrganization(name: 'Acme');

    expect($a->slug)->toBe('acme')->and($b->slug)->toBe('acme-2');
});
