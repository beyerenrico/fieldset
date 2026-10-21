<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\JsonResult;
use App\Domain\Platform\Instance\Upgrade\UpgradeMigrator;
use App\Domain\Platform\Instance\Upgrade\UpgradeRefused;
use App\Domain\Platform\Instance\Upgrade\UpgradeSessions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The upgrade child's database work (`fsa upgrade` step 6, and a restore of an older backup; plan U18, ADR 0086): zero
 * foreign sessions, `lock_timeout`, the pending migrations, the version steps since `--from`, and no index left invalid
 * (UpgradeMigrator). It runs in the target image, on DB_DATABASE, without the Docker socket.
 */
#[Description('Migrate the database for an upgrade: alone, with lock_timeout, version steps and an index check')]
#[Signature('instance:upgrade-migrate
        {--from= : The version the instance comes from (version steps since then run); empty runs them all}
        {--application-name= : The session name fsa watches (fieldset_upgrade_<hex>)}
        {--wait-foreign=30 : Seconds to wait for other sessions on the database to end}
        {--lock-timeout=60 : Seconds a statement may wait for a lock}
        {--json : Print one JSON document on stdout}')]
final class UpgradeMigrateCommand extends Command
{
    private const string NAME = 'instance:upgrade-migrate';

    public function handle(UpgradeMigrator $migrator): int
    {
        try {
            $db = DB::connection();
            if (! $db instanceof Connection) {
                throw new InvalidArgumentException('The upgrade needs a database connection.');
            }
            if ($this->option('application-name') !== null) {
                UpgradeSessions::application($this->option('application-name'));
            }
            $data = $migrator->run($db, $this->from(), $this->seconds('wait-foreign'), max(1, $this->seconds('lock-timeout')));
        } catch (UpgradeRefused $e) {
            return $this->refuse($e->errorCode, $e->getMessage(), $e->hint);
        } catch (InvalidArgumentException $e) {
            return $this->refuse('invalid_input', $e->getMessage());
        } catch (Throwable $e) {
            return $this->refuse('upgrade_migrate_failed', $e->getMessage());
        }
        if ($this->option('json') === true) {
            return JsonResult::ok(self::NAME, $data, changed: $data['migrated'] !== [] || $data['steps'] !== [])->emit($this);
        }
        $this->components->info(count($data['migrated']).' migration(s) and '.count($data['steps']).' version step(s) ran.');

        return self::SUCCESS;
    }

    private function from(): ?string
    {
        $from = $this->option('from');
        if ($from === null || $from === '') {
            return null;
        }
        if (! is_string($from) || preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $from) !== 1) {
            throw new InvalidArgumentException('--from must be a release version.');
        }

        return $from;
    }

    private function seconds(string $option): int
    {
        $value = $this->option($option);
        if (! is_string($value) || preg_match('/^\d{1,5}$/D', $value) !== 1) {
            throw new InvalidArgumentException("--{$option} must be a number of seconds.");
        }

        return (int) $value;
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
