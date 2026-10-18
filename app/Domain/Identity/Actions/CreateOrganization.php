<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Demo\DemoAccounts;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates an organization, seeds its roles and makes the given user its Owner.
 *
 * The slug `demo` is reserved for the demo organization (R29, R65, ADR 0081): a generated slug skips it (a real
 * organization named "Demo" gets `demo-2`), and an explicit one is refused. Only the demo seeder passes `demo: true`,
 * which marks the organization `is_demo` (what every demo decision reads) and gives it `demo`, or the next free slug
 * when a real organization took `demo` before the reservation.
 */
final readonly class CreateOrganization
{
    /** Slugs only the demo organization may have. */
    public const array RESERVED_SLUGS = [DemoAccounts::ORGANIZATION_SLUG];

    public function __construct(
        private SeedOrganizationRoles $seedRoles,
        private AddMember $addMember,
    ) {}

    /**
     * @param  array{name: string, slug?: string|null, locale?: string|null, timezone?: string|null}  $data
     * @param  bool  $demo  the demo organization (DemoSeeder only)
     *
     * @throws InvalidArgumentException for a reserved slug outside the demo
     */
    public function handle(array $data, User $owner, bool $demo = false): Organization
    {
        $slug = $data['slug'] ?? null;
        if (! $demo && $slug !== null && in_array($slug, self::RESERVED_SLUGS, true)) {
            throw new InvalidArgumentException("The slug [{$slug}] is reserved for the demo organization.");
        }

        return DB::transaction(function () use ($data, $owner, $demo, $slug): Organization {
            $organization = Organization::query()->make([
                'name' => $data['name'],
                'slug' => $demo ? $this->uniqueSlug($slug ?? DemoAccounts::ORGANIZATION_SLUG, true) : ($slug ?? $this->uniqueSlug($data['name'], false)),
                'locale' => $data['locale'] ?? config('app.locale', 'de'),
                'timezone' => $data['timezone'] ?? 'Europe/Berlin',
            ]);
            $organization->forceFill(['is_demo' => $demo])->save();

            $this->seedRoles->handle($organization);
            $this->addMember->handle($organization, $owner, [SystemRole::Owner]);

            return $organization;
        });
    }

    private function uniqueSlug(string $name, bool $demo): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $i = 2;

        while ((! $demo && in_array($slug, self::RESERVED_SLUGS, true)) || Organization::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
