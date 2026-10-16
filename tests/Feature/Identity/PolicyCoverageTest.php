<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\Finder\Finder;

it('registers a policy for every Eloquent model', function (): void {
    $missing = [];
    foreach (Finder::create()->files()->in(app_path('Domain'))->path('Models')->name('*.php') as $file) {
        $class = 'App\\Domain\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        if (class_exists($class) && is_subclass_of($class, Model::class) && Gate::getPolicyFor($class) === null) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe([]);
});

it('lets users see only their own account', function (): void {
    [$a, $b] = User::factory()->count(2)->create();

    expect($a->can('view', $a))->toBeTrue()->and($a->can('view', $b))->toBeFalse()->and($a->can('delete', $a))->toBeFalse();
});

it('shows audit entries only to audit.view holders of the same organization', function (): void {
    $org = makeOrganization();
    $other = makeOrganization(name: 'Other');
    app(TenantContext::class)->set($other);
    $foreign = app(AuditLogger::class)->record('x.y');
    app(TenantContext::class)->set($org);
    $own = app(AuditLogger::class)->record('x.y');
    $owner = ownerOf($org);
    $designer = memberOf($org, SystemRole::Designer);

    expect($owner->can('view', $own))->toBeTrue()
        ->and($owner->can('view', $foreign))->toBeFalse()
        ->and($designer->can('view', $own))->toBeFalse()
        ->and($owner->can('update', $own))->toBeFalse();
});
