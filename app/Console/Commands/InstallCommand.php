<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Actions\CreateOrganization;
use App\Domain\Identity\Actions\SyncPermissionCatalog;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/** Creates the first organization and its owner (BUILD_PROMPT §11 Operability). */
#[Description('Create the first organization and its owner account')]
#[Signature('app:install
        {--organization= : Organization name}
        {--name= : Owner name}
        {--email= : Owner email}
        {--password= : Owner password (prompted when omitted)}
        {--locale=de : Organization locale (de|en)}')]
final class InstallCommand extends Command
{
    public function handle(SyncPermissionCatalog $catalog, CreateOrganization $createOrganization): int
    {
        if (Organization::query()->withTrashed()->exists()) {
            $this->components->warn('Already installed: an organization exists. Nothing was changed.');

            return self::SUCCESS;
        }

        $data = [
            'organization' => $this->stringOption('organization') ?? text('Organization name', required: true),
            'name' => $this->stringOption('name') ?? text('Owner name', required: true),
            'email' => Str::lower($this->stringOption('email') ?? text('Owner email', required: true)),
            'password' => $this->stringOption('password') ?? password('Owner password', required: true),
            'locale' => $this->stringOption('locale') ?? 'de',
        ];

        $validator = Validator::make($data, [
            'organization' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', Password::min(10)],
            'locale' => ['required', 'in:de,en'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $catalog->handle();

        $owner = User::query()->firstOrCreate(['email' => $data['email']], [
            'name' => $data['name'],
            'password' => $data['password'],
            'locale' => $data['locale'],
        ]);
        $owner->markEmailAsVerified();

        $organization = $createOrganization->handle(['name' => (string) $data['organization'], 'locale' => (string) $data['locale']], $owner);

        $this->components->info("Organization [{$organization->name}] created. Sign in at ".url('/admin/login')." as {$owner->email}.");

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
