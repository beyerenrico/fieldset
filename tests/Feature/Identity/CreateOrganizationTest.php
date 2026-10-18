<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\CreateOrganization;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;

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

it('reserves the slug demo for the demo organization: a real one named Demo gets another slug', function (): void {
    $slugs = array_map(static fn (string $name): string => makeOrganization(name: $name)->slug, ['Demo', 'DEMO', 'Démo!']);

    expect($slugs)->toBe(['demo-2', 'demo-3', 'demo-4'])
        ->and(fn () => app(CreateOrganization::class)->handle(['name' => 'Anything', 'slug' => 'demo'], User::factory()->create()))
        ->toThrow(InvalidArgumentException::class, 'reserved')
        ->and(Organization::query()->where('slug', 'demo')->exists())->toBeFalse()
        ->and(Organization::query()->where('is_demo', true)->exists())->toBeFalse();
});

it('marks only the demo organization, which takes the reserved slug, and never through mass assignment', function (): void {
    $demo = app(CreateOrganization::class)->handle(['name' => 'Demo Haustechnik GmbH'], User::factory()->create(), demo: true);
    $real = makeOrganization(name: 'Demo');

    expect($demo->slug)->toBe('demo')
        ->and($demo->fresh()?->is_demo)->toBeTrue()
        ->and($real->slug)->toBe('demo-2')
        ->and($real->fresh()?->is_demo)->toBeFalse()
        ->and(fn () => Organization::query()->create(['name' => 'Sneaky', 'slug' => 'sneaky', 'is_demo' => true]))->toThrow(MassAssignmentException::class);
});

it('gives the demo another slug when a real organization took demo before the reservation', function (): void {
    Organization::factory()->create(['name' => 'Demo', 'slug' => 'demo']);

    $demo = app(CreateOrganization::class)->handle(['name' => 'Demo Haustechnik GmbH'], User::factory()->create(), demo: true);

    expect($demo->slug)->toBe('demo-2')->and($demo->fresh()?->is_demo)->toBeTrue();
});
