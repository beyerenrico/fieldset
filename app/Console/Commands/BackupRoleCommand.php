<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\JsonResult;
use App\Domain\Platform\Instance\BackupRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Creates or updates the backup service's database role (`fsa install` after its migrations, `fsa backup` before each
 * manual run; ADR 0084) in a socket-less one-off of the app service. The password comes from the environment variable
 * FSA_BACKUP_DB_PASSWORD, which fsa passes by name, never as an argument, and is never printed.
 */
#[Description('Create or update the database role of the fsa backup service (read all data, insert backup runs)')]
#[Signature('instance:backup-role
        {--json : Print one JSON document on stdout}')]
final class BackupRoleCommand extends Command
{
    private const string COMMAND = 'instance:backup-role';

    public function handle(BackupRole $role): int
    {
        $password = getenv(BackupRole::PASSWORD_ENV);
        if (! is_string($password) || $password === '') {
            return $this->refuse('password_missing', BackupRole::PASSWORD_ENV.' is not set.');
        }

        try {
            $data = $role->ensure($password);
        } catch (RuntimeException $e) {
            return $this->refuse('backup_role_failed', $e->getMessage());
        }
        if ($this->option('json') === true) {
            return JsonResult::ok(self::COMMAND, $data, changed: true)->emit($this);
        }
        $this->components->info("The backup role {$data['role']} is ".($data['created'] ? 'created' : 'up to date').'.');

        return self::SUCCESS;
    }

    private function refuse(string $code, string $message): int
    {
        if ($this->option('json') === true) {
            JsonResult::failed(self::COMMAND, $code, $message)->emit($this);

            return self::FAILURE;
        }
        $this->components->error($message);

        return self::FAILURE;
    }
}
