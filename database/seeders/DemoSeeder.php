<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Actions\CreateOrganization;
use App\Domain\Identity\Actions\SyncMemberGroups;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Demo organization with one user per persona. Password for every demo user: "password".
 * Never run in production (DatabaseSeeder guards this).
 */
final class DemoSeeder extends Seeder
{
    public const string PASSWORD = 'password';

    public function run(CreateOrganization $create, AddMember $add, SyncMemberGroups $groups, TenantContext $tenant): void
    {
        if (Organization::query()->where('slug', 'demo')->exists()) {
            return;
        }

        $owner = $this->user('owner@demo.test', 'Olivia Owner');
        $org = $create->handle(['name' => 'Demo Haustechnik GmbH', 'slug' => 'demo', 'locale' => 'de'], $owner);

        [$north, $office] = $tenant->run($org, fn (): array => [
            Group::query()->create(['name' => 'Team Nord', 'description' => 'Montage und Service Nord']),
            Group::query()->create(['name' => 'Büro', 'description' => 'Innendienst']),
        ]);

        $people = [
            ['admin@demo.test', 'Adam Admin', [SystemRole::Admin], [$office->id]],
            ['designer@demo.test', 'Dana Designer', [SystemRole::Designer], [$office->id]],
            ['reviewer@demo.test', 'Rita Reviewer', [SystemRole::Reviewer], [$office->id]],
            ['coordinator@demo.test', 'Cora Coordinator', [SystemRole::Designer, SystemRole::Reviewer], [$office->id]],
            ['worker@demo.test', 'Willi Worker', [SystemRole::FieldWorker], [$north->id]],
            ['viewer@demo.test', 'Vera Viewer', [SystemRole::Viewer], []],
        ];

        foreach ($people as [$email, $name, $roles, $groupIds]) {
            $user = $this->user($email, $name);
            $add->handle($org, $user, $roles);
            $groups->handle($org, $user, $groupIds);
        }
    }

    private function user(string $email, string $name): User
    {
        return User::query()->firstOrCreate(['email' => $email], [
            'name' => $name,
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ]);
    }
}
