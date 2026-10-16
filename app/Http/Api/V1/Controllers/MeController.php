<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OrganizationPermissions;
use App\Http\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /me — the user, their organizations, roles, groups and org settings the app needs. */
final class MeController
{
    public function __invoke(Request $request, OrganizationPermissions $permissions): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $organizations = $user->activeOrganizations()->orderBy('name')->get()->map(
            fn (Organization $org): array => [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->slug,
                'locale' => $org->locale,
                'timezone' => $org->timezone,
                'branding' => $org->branding,
                'roles' => $this->roleNames($user, $org),
                'permissions' => $permissions->for($user, $org),
                'groups' => $user->groups()->wherePivot('organization_id', $org->id)
                    ->withoutTenant()->get(['groups.id', 'groups.name'])
                    ->map(fn ($g): array => ['id' => $g->id, 'name' => $g->name])->all(),
            ],
        )->all();

        return ApiResponse::ok([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'timezone' => $user->timezone,
                'email_verified' => $user->hasVerifiedEmail(),
                'mfa_enabled' => $user->getAppAuthenticationSecret() !== null,
            ],
            'organizations' => $organizations,
        ]);
    }

    /** @return list<string> */
    private function roleNames(User $user, Organization $org): array
    {
        return array_values(Role::query()
            ->join('model_has_roles as mhr', 'mhr.role_id', '=', 'roles.id')
            ->where('mhr.model_type', $user->getMorphClass())
            ->where('mhr.model_id', $user->id)
            ->where('mhr.organization_id', $org->id)
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all());
    }
}
