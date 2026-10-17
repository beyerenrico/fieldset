<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Auth\SharedAppAuthentication;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\GrantPlatformAdmin;
use App\Domain\Platform\Actions\RevokePlatformAdmin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Grants or revokes the /platform operator role (BUILD_PROMPT §8.12, ADR 0041). None is seeded. */
#[Description('Grant (or revoke with --revoke) the platform operator role for an existing user')]
#[Signature('app:platform-admin
        {email : Email of an existing user}
        {--revoke : Revoke the role instead of granting it}')]
final class PlatformAdminCommand extends Command
{
    public function handle(GrantPlatformAdmin $grant, RevokePlatformAdmin $revoke): int
    {
        $argument = $this->argument('email');
        $email = is_string($argument) ? Str::lower(trim($argument)) : '';
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->components->error("No user with the email [{$email}].");

            return self::FAILURE;
        }

        if ($this->option('revoke') === true) {
            $revoke->handle($user)
                ? $this->components->info("Platform operator role revoked for [{$email}].")
                : $this->components->warn("[{$email}] is not a platform operator. Nothing was changed.");

            return self::SUCCESS;
        }

        if (! $user->isActive()) {
            $this->components->error("[{$email}] is deactivated: reactivate the account first.");

            return self::FAILURE;
        }

        $grant->handle($user)
            ? $this->components->info("Platform operator role granted to [{$email}]. Sign in at ".url('/platform/login').'.')
            : $this->components->warn("[{$email}] is already a platform operator. Nothing was changed.");

        if (! SharedAppAuthentication::make()->isEnabled($user)) {
            $this->components->warn('Multi-factor authentication is required on /platform: the first sign-in asks to set it up.');
        }

        return self::SUCCESS;
    }
}
