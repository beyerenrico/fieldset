<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use Database\Seeders\DemoSeeder;

it('installs the first organization and owner', function (): void {
    $this->artisan('app:install', [
        '--organization' => 'Müller GmbH', '--name' => 'Max Müller',
        '--email' => 'Max@Example.com', '--password' => 'long-enough-pw', '--locale' => 'de',
    ])->assertSuccessful();

    $org = Organization::query()->sole();
    $owner = User::query()->where('email', 'max@example.com')->sole();
    expect($org->name)->toBe('Müller GmbH')
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and($owner->hasPermissionIn($org, Permission::OrganizationDelete))->toBeTrue();
});

it('rejects weak input without creating anything', function (): void {
    $this->artisan('app:install', [
        '--organization' => 'X', '--name' => 'Y', '--email' => 'not-an-email', '--password' => 'short',
    ])->assertFailed();

    expect(Organization::query()->count())->toBe(0);
});

it('seeds one demo user per persona, idempotently', function (): void {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $org = Organization::query()->where('slug', 'demo')->sole();
    $coordinator = User::query()->where('email', 'coordinator@demo.test')->sole();
    expect($org->members()->count())->toBe(7)
        ->and($coordinator->hasPermissionIn($org, Permission::FormsManage))->toBeTrue()
        ->and($coordinator->hasPermissionIn($org, Permission::SubmissionsReview))->toBeTrue();
});
