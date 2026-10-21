<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\JsonResult;
use App\Domain\Platform\Instance\Upgrade\UpgradeSessions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * What the upgrade supervisor reads and does in the database without opening it itself (KTD25, plan U18, ADR 0086):
 * `activity` and `terminate` of the upgrade child's session (liveness, a hung child), `invalid-indexes` after the
 * migrations, `foreign` (the client sessions requests still hold after the gate rose) and `size` (the live database
 * and the storage volume, for the space check) (UpgradeSessions). Runs as a socket-less one-off of the app service.
 */
#[Description('The upgrade child\'s session, other sessions, sizes and the index check, for fsa upgrade')]
#[Signature('instance:upgrade-db
        {action : activity, terminate, foreign, size or invalid-indexes}
        {--application-name= : The child\'s session name (fieldset_upgrade_<hex>)}
        {--json : Print one JSON document on stdout}')]
final class UpgradeDatabaseCommand extends Command
{
    private const string NAME = 'instance:upgrade-db';

    public function handle(): int
    {
        try {
            $db = DB::connection();
            if (! $db instanceof Connection) {
                throw new InvalidArgumentException('The upgrade needs a database connection.');
            }
            $sessions = new UpgradeSessions($db);
            $data = match ($this->argument('action')) {
                'activity' => ['backends' => $sessions->activity(UpgradeSessions::application($this->option('application-name')))],
                'terminate' => ['terminated' => $sessions->terminate(UpgradeSessions::application($this->option('application-name')))],
                'invalid-indexes' => ['indexes' => $sessions->invalidIndexes()],
                'foreign' => ['sessions' => $sessions->foreign()],
                'size' => ['database_bytes' => $sessions->databaseBytes(), 'storage_bytes' => $this->bytes(storage_path())],
                default => throw new InvalidArgumentException('The action must be activity, terminate, foreign, size or invalid-indexes.'),
            };
        } catch (InvalidArgumentException $e) {
            return $this->refuse('invalid_input', $e->getMessage());
        } catch (Throwable $e) {
            return $this->refuse('database_failed', $e->getMessage());
        }
        if ($this->option('json') === true) {
            return JsonResult::ok(self::NAME, $data, changed: ($data['terminated'] ?? 0) > 0)->emit($this);
        }
        $this->line((string) json_encode($data, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** Bytes of the regular files below a directory (symlinks not followed): the storage volume the app mounts there. */
    private function bytes(string $directory): int
    {
        if (! is_dir($directory)) {
            return 0;
        }
        $bytes = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && ! $file->isLink()) {
                $bytes += (int) $file->getSize();
            }
        }

        return $bytes;
    }

    private function refuse(string $code, string $message): int
    {
        if ($this->option('json') === true) {
            return JsonResult::failed(self::NAME, $code, $message)->emit($this);
        }
        $this->components->error($message);

        return self::FAILURE;
    }
}
