<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\SystemRole;

/*
 * The capability matrix from BUILD_PROMPT §8.6, written out literally (not derived from
 * SystemRole::permissions()) so the test guards the spec. ✓ = granted.
 * "View all submissions" is split into the scope-specific permissions it implies.
 */
dataset('matrix', function (): array {
    $matrix = [
        //                               owner admin designer reviewer worker viewer
        'organization.manage' => [1, 1, 0, 0, 0, 0],
        'members.manage' => [1, 1, 0, 0, 0, 0],
        'forms.manage' => [1, 1, 1, 0, 0, 0],
        'workflows.manage' => [1, 1, 1, 0, 0, 0],
        'submissions.review' => [1, 1, 0, 1, 0, 0],
        'forms.fill' => [1, 1, 1, 1, 1, 0],
        'forms.fill_all' => [1, 1, 0, 0, 0, 0],
        'submissions.view_all' => [1, 1, 0, 0, 0, 1],
        'submissions.view_own_forms' => [1, 1, 1, 0, 0, 0],
        'submissions.view_assigned' => [1, 1, 0, 1, 0, 0],
        'submissions.view_own' => [1, 1, 1, 1, 1, 0],
        'reports.view' => [1, 1, 1, 1, 0, 1],
        'audit.view' => [1, 1, 0, 0, 0, 0],
        'organization.delete' => [1, 0, 0, 0, 0, 0],
        'admin.access' => [1, 1, 1, 1, 0, 1],
    ];
    $roles = ['owner', 'admin', 'designer', 'reviewer', 'field_worker', 'viewer'];
    $cases = [];

    foreach ($matrix as $permission => $row) {
        foreach ($roles as $i => $role) {
            $cases["{$role} / {$permission}"] = [$role, $permission, (bool) $row[$i]];
        }
    }

    return $cases;
});

it('grants exactly the §8.6 matrix', function (string $role, string $permission, bool $expected): void {
    $org = makeOrganization();
    $user = memberOf($org, SystemRole::from($role));

    expect($user->hasPermissionIn($org, Permission::from($permission)))->toBe($expected);
})->with('matrix');

it('covers every permission in the catalog', function (): void {
    $tested = ['organization.manage', 'members.manage', 'forms.manage', 'workflows.manage', 'submissions.review',
        'forms.fill', 'forms.fill_all', 'submissions.view_all', 'submissions.view_own_forms', 'submissions.view_assigned',
        'submissions.view_own', 'reports.view', 'audit.view', 'organization.delete', 'admin.access'];

    expect(Permission::values())->toEqualCanonicalizing($tested);
});

it('makes roles additive (Office Coordinator = Designer + Reviewer)', function (): void {
    $org = makeOrganization();
    $coordinator = memberOf($org, SystemRole::Designer, SystemRole::Reviewer);

    expect($coordinator->hasPermissionIn($org, Permission::FormsManage))->toBeTrue()
        ->and($coordinator->hasPermissionIn($org, Permission::SubmissionsReview))->toBeTrue()
        ->and($coordinator->hasPermissionIn($org, Permission::MembersManage))->toBeFalse();
});

it('scopes roles to the organization', function (): void {
    $orgA = makeOrganization(name: 'A');
    $orgB = makeOrganization(name: 'B');
    $user = memberOf($orgA, SystemRole::Admin);
    app(AddMember::class)->handle($orgB, $user, [SystemRole::Viewer]);

    expect($user->hasPermissionIn($orgA, Permission::MembersManage))->toBeTrue()
        ->and($user->hasPermissionIn($orgB, Permission::MembersManage))->toBeFalse()
        ->and($user->hasPermissionIn($orgB, Permission::ReportsView))->toBeTrue();
});
