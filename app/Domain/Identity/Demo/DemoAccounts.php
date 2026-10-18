<?php

declare(strict_types=1);

namespace App\Domain\Identity\Demo;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The demo organization and its accounts (DemoSeeder, BUILD_PROMPT_V2 R29): one user per persona with the roles and
 * groups the seeder gives them. The table is the single list of "demo users": the seeder creates them, `fsa demo
 * --rotate-passwords` rotates exactly these (ADR 0081).
 */
final class DemoAccounts
{
    public const string ORGANIZATION_SLUG = 'demo';

    public const string ORGANIZATION_NAME = 'Demo Haustechnik GmbH';

    public const string OWNER = 'owner@demo.test';

    public const string OWNER_NAME = 'Olivia Owner';

    /** Group keys of the seeder: `north` = Team Nord, `office` = Büro. */
    public const array GROUPS = ['north', 'office'];

    /**
     * email => [name, roles, groups]. The extra field workers serve the offline E2E (M6): one is deactivated and
     * reactivated there, and the journeys spread their logins (the login limit is 5 per minute per email and IP).
     *
     * @var array<string, array{0: string, 1: list<SystemRole>, 2: list<string>}>
     */
    public const array PEOPLE = [
        'admin@demo.test' => ['Adam Admin', [SystemRole::Admin], ['office']],
        'designer@demo.test' => ['Dana Designer', [SystemRole::Designer], ['office']],
        'reviewer@demo.test' => ['Rita Reviewer', [SystemRole::Reviewer], ['office']],
        'coordinator@demo.test' => ['Cora Coordinator', [SystemRole::Designer, SystemRole::Reviewer], ['office']],
        'worker@demo.test' => ['Willi Worker', [SystemRole::FieldWorker], ['north']],
        'mobile@demo.test' => ['Mia Mobil', [SystemRole::FieldWorker], ['north']],
        'tablet@demo.test' => ['Tom Tablet', [SystemRole::FieldWorker], ['north']],
        'viewer@demo.test' => ['Vera Viewer', [SystemRole::Viewer], []],
    ];

    /** @return list<string> every demo account, the owner first */
    public static function emails(): array
    {
        return [self::OWNER, ...array_keys(self::PEOPLE)];
    }

    /** @return list<array{email: string, name: string, roles: list<string>}> the personas for `fsa demo` output */
    public static function personas(): array
    {
        $people = array_map(
            static fn (string $email, array $person): array => ['email' => $email, 'name' => $person[0], 'roles' => array_map(static fn (SystemRole $role): string => $role->value, $person[1])],
            array_keys(self::PEOPLE),
            self::PEOPLE,
        );

        return [['email' => self::OWNER, 'name' => self::OWNER_NAME, 'roles' => [SystemRole::Owner->value]], ...$people];
    }

    /** The demo organization, also when it was deleted (a deleted one still blocks the slug). */
    public static function organization(): ?Organization
    {
        return Organization::query()->withTrashed()->where('slug', self::ORGANIZATION_SLUG)->first();
    }

    /** @return Collection<int, User> the demo accounts that exist, whatever their status */
    public static function users(): Collection
    {
        return User::query()->whereIn('email', self::emails())->orderBy('id')->get();
    }
}
