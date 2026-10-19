<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\JsonResult;
use App\Domain\Platform\Instance\Restore\DatabaseAdmin;
use App\Domain\Platform\Instance\Restore\RestoreConnections;
use App\Domain\Platform\Instance\Restore\RestoreRefused;
use App\Domain\Platform\Instance\Restore\SchemaAudit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Database-level steps of `fsa restore`, the drill and `fsa install --from-backup` (plan U17, ADR 0085), in a
 * socket-less one-off of the app service (KTD25) on the maintenance database: `create` (with --restricted-role for an
 * archive without a valid HMAC; its password comes from FSA_RESTORE_DB_PASSWORD by name), `drop`, `swap`, `adopt`,
 * `audit` (SchemaAudit: an unauthenticated archive's code-bearing objects against a database this image migrated) and
 * `list` (DatabaseAdmin). One JSON document with --json; never a password.
 */
#[Description('Create, swap, adopt, drop or list the databases of an fsa restore')]
#[Signature('instance:restore-db
        {action : create, drop, swap, adopt, audit or list}
        {--database= : The database to create, drop or adopt; with swap the incoming one}
        {--restricted-role= : create: the role that owns the new database (password in FSA_RESTORE_DB_PASSWORD)}
        {--role= : drop or adopt: the restricted role}
        {--live= : swap: the live database name}
        {--aside= : swap: the name the live database moves to}
        {--prefix= : list: the name prefix}
        {--reference= : audit: the database whose schema the restored one must match (migrated by this image)}
        {--json : Print one JSON document on stdout}')]
final class RestoreDatabaseCommand extends Command
{
    public const string PASSWORD_ENV = 'FSA_RESTORE_DB_PASSWORD';

    private const string NAME = 'instance:restore-db';

    public function handle(): int
    {
        $action = $this->argument('action');
        $action = is_string($action) ? $action : '';

        try {
            $admin = new DatabaseAdmin(RestoreConnections::maintenance());
            $data = $this->perform($admin, $action);
        } catch (InvalidArgumentException $e) {
            return $this->refuse('invalid_input', $e->getMessage());
        } catch (RestoreRefused $e) {
            return $this->refuse($e->errorCode, $e->getMessage(), $e->hint);
        } catch (QueryException $e) {
            return $this->refuse('database_failed', 'The database step failed (SQLSTATE '.($e->errorInfo[0] ?? '?').'): '.($e->errorInfo[2] ?? $e->getPrevious()?->getMessage() ?? 'no message'));
        } finally {
            RestoreConnections::close('fsa_restore_maintenance');
        }
        if ($this->option('json') === true) {
            return JsonResult::ok(self::NAME, $data, changed: $action !== 'list')->emit($this);
        }
        $this->components->info(self::NAME.' '.$action.': done.');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function perform(DatabaseAdmin $admin, string $action): array
    {
        return match ($action) {
            'create' => $admin->create($this->name('database'), $this->optionalName('restricted-role'), $this->password()),
            'drop' => $admin->drop($this->name('database'), $this->optionalName('role'), RestoreConnections::current()),
            'swap' => $admin->swap($this->name('database'), $this->name('live'), $this->name('aside')),
            'adopt' => $admin->adopt($this->name('database'), $this->name('role')),
            'list' => ['databases' => $admin->list($this->option('prefix') === '' ? '' : $this->name('prefix'))], // '' lists every database
            'audit' => $this->audit($this->name('database'), $this->name('reference')),
            default => throw new InvalidArgumentException('The action must be create, drop, swap, adopt, audit or list.'),
        };
    }

    /**
     * @return array{database: string, reference: string, objects: int}
     *
     * @throws RestoreRefused `schema_mismatch` naming the objects
     */
    private function audit(string $database, string $reference): array
    {
        try {
            $restored = RestoreConnections::to($database, 'fsa_restore_audit');
            $expected = RestoreConnections::to($reference, 'fsa_restore_reference');
            $mismatches = SchemaAudit::mismatches($restored, $expected);
            $objects = count(SchemaAudit::objects($restored)) + count(SchemaAudit::expressions($restored));
        } finally {
            RestoreConnections::close('fsa_restore_audit');
            RestoreConnections::close('fsa_restore_reference');
        }
        if ($mismatches !== []) {
            throw new RestoreRefused('schema_mismatch', 'The restored database holds code this version does not define: '.implode('; ', array_slice($mismatches, 0, 10)).'.', 'Nothing was swapped in. Restore only archives this app wrote.');
        }

        return ['database' => $database, 'reference' => $reference, 'objects' => $objects];
    }

    private function name(string $option): string
    {
        return RestoreConnections::name($this->option($option), $option);
    }

    private function optionalName(string $option): ?string
    {
        $value = $this->option($option);

        return $value === null || $value === '' ? null : RestoreConnections::name($value, $option);
    }

    private function password(): ?string
    {
        if ($this->optionalName('restricted-role') === null) {
            return null;
        }
        $password = getenv(self::PASSWORD_ENV);

        return is_string($password) && $password !== '' ? $password : null;
    }

    private function refuse(string $code, string $message, ?string $hint = null): int
    {
        if ($this->option('json') === true) {
            return JsonResult::failed(self::NAME, $code, $message, $hint)->emit($this);
        }
        $this->components->error($message);

        return self::FAILURE;
    }
}
