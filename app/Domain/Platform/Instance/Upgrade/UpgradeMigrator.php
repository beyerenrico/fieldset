<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Upgrade;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The database work of an upgrade child (KTD19 step 6, R36, plan U18, ADR 0086), and of a restore of an older backup:
 *
 * 1. zero foreign sessions on the database, waited for briefly (writers are stopped, so one that stays is a mistake:
 *    migrating beside it could block on its locks or change data under it);
 * 2. `lock_timeout` for the session, so a migration never waits on a lock indefinitely;
 * 3. the pending migrations (upgrade migrations never use `CONCURRENTLY`: writers are stopped, and a concurrent index
 *    build left half-done would leave an invalid index; a test refuses it in database/migrations);
 * 4. the version steps from the version the instance comes from;
 * 5. no index left invalid.
 */
final readonly class UpgradeMigrator
{
    /** Container key of the migrations path (tests); the release's own database/migrations otherwise. */
    public const string PATH = 'instance.upgrade-migrate.path';

    public function __construct(private Migrator $migrator, private VersionSteps $steps) {}

    /**
     * @return array{migrated: list<string>, steps: list<string>, invalid_indexes: list<string>, lock_timeout: string}
     *
     * @throws UpgradeRefused `foreign_connections`, `migration_failed`, `invalid_index`
     */
    public function run(Connection $db, ?string $from, int $waitForeign, int $lockTimeout): array
    {
        $sessions = new UpgradeSessions($db);
        $this->awaitAlone($sessions, $waitForeign);
        $db->statement("SET lock_timeout = '".max(1, $lockTimeout)."s'");
        $migrated = $this->migrate($db);
        $steps = $this->steps->run($db, $from);
        $invalid = $sessions->invalidIndexes();
        if ($invalid !== []) {
            throw new UpgradeRefused('invalid_index', 'Indexes are left invalid: '.implode(', ', $invalid).'.', 'The upgrade rolls back to the safety backup.');
        }

        return ['migrated' => $migrated, 'steps' => $steps, 'invalid_indexes' => [], 'lock_timeout' => (string) $db->selectOne('SHOW lock_timeout')->lock_timeout];
    }

    /**
     * The pending migrations of the release (and of packages that register their own paths), through Laravel's migrator
     * on this connection: the same run `migrate --force` does, without the console command's output handling.
     *
     * @return list<string> the migrations that ran
     *
     * @throws UpgradeRefused `migration_failed`
     */
    private function migrate(Connection $db): array
    {
        $paths = app()->bound(self::PATH) ? [(string) app(self::PATH)] : [...$this->migrator->paths(), database_path('migrations')];
        try {
            return $this->migrator->usingConnection($db->getName(), function () use ($paths): array {
                if (! $this->migrator->repositoryExists()) {
                    $this->migrator->getRepository()->createRepository();
                }

                return array_values(array_map(static fn (string $file): string => basename($file, '.php'), $this->migrator->run($paths)));
            });
        } catch (Throwable $e) {
            throw new UpgradeRefused('migration_failed', 'The migrations failed: '.$e->getMessage(), 'The upgrade rolls back to the safety backup.', $e);
        }
    }

    /** @throws UpgradeRefused `foreign_connections` when other sessions stay beyond the wait */
    private function awaitAlone(UpgradeSessions $sessions, int $seconds): void
    {
        $deadline = CarbonImmutable::now()->addSeconds(max(0, $seconds));
        while (($foreign = $sessions->foreign()) !== []) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                $names = array_map(static fn (array $session): string => ($session['application_name'] !== '' ? $session['application_name'] : 'unnamed').' ('.($session['user'] ?? '?').', '.($session['state'] ?? '?').')', $foreign);
                throw new UpgradeRefused('foreign_connections', count($foreign).' other session(s) use the database: '.implode(', ', $names).'.', 'Stop whatever still connects (writers are stopped during an upgrade), then retry.');
            }
            Sleep::for(1)->second();
        }
    }
}
