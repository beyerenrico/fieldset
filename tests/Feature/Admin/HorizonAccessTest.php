<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

it('lets only configured platform admins view Horizon', function (): void {
    config(['app.platform_admins' => ['ops@example.com']]);
    $ops = User::factory()->create(['email' => 'ops@example.com']);
    $owner = User::factory()->create();

    expect(Gate::forUser($ops)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});
