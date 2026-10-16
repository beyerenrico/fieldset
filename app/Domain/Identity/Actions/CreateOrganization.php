<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Creates an organization, seeds its roles and makes the given user its Owner. */
final readonly class CreateOrganization
{
    public function __construct(
        private SeedOrganizationRoles $seedRoles,
        private AddMember $addMember,
    ) {}

    /**
     * @param  array{name: string, slug?: string|null, locale?: string|null, timezone?: string|null}  $data
     */
    public function handle(array $data, User $owner): Organization
    {
        return DB::transaction(function () use ($data, $owner): Organization {
            $organization = Organization::query()->create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? $this->uniqueSlug($data['name']),
                'locale' => $data['locale'] ?? config('app.locale', 'de'),
                'timezone' => $data['timezone'] ?? 'Europe/Berlin',
            ]);

            $this->seedRoles->handle($organization);
            $this->addMember->handle($organization, $owner, [SystemRole::Owner]);

            return $organization;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $i = 2;

        while (Organization::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
