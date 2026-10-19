<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Docker\CommandRunner;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;

/**
 * The child's second half (KTD19 step 6, R36, plan U18, ADR 0086), in the target image: `instance:upgrade-migrate` as a
 * subprocess with the app's environment as the target rendered it (staged `env/app.env`), so new keys the target's
 * migrations read are there. libpq's own variables name the session `fsa_upgrade_<id>`, by which the supervisor finds
 * its backend in `pg_stat_activity` (liveness, termination), and set `lock_timeout` for every statement. The app side
 * requires zero foreign connections first, migrates (never `CONCURRENTLY`: writers are stopped), runs the version steps
 * from the installed version on, and checks that no index is invalid.
 */
final readonly class ChildMigration
{
    /** Seconds a statement may wait for a lock before it fails (and the upgrade rolls back). */
    public const int LOCK_TIMEOUT_SECONDS = 60;

    /** The overall limit of the migration subprocess (a large instance's data migrations). */
    public const float TIMEOUT = 21600.0;

    public function __construct(private CommandRunner $runner) {}

    /** The session name of a journal's child (`application_name`). */
    public static function applicationName(string $id): string
    {
        return 'fsa_upgrade_'.$id;
    }

    /** @return array<string, string> libpq's environment for the child's session */
    public static function libpq(string $id): array
    {
        return ['PGAPPNAME' => self::applicationName($id), 'PGOPTIONS' => '-c lock_timeout='.self::LOCK_TIMEOUT_SECONDS.'s'];
    }

    /** Runs the migration with the staged app environment; the app side's JSON document decides. */
    public function run(string $stage, string $id, ?string $from): OneOffResult
    {
        $files = InstanceDirectory::open($stage.'/'.StageRenderer::FILES);
        $env = [...new EnvFiles($files)->read(ComposeRenderer::ONE_OFF_SERVICE), ...self::libpq($id)];
        $command = ['php', base_path('artisan'), 'instance:upgrade-migrate', '--application-name='.self::applicationName($id), ...($from === null ? [] : ['--from='.$from]), '--json'];
        $outcome = $this->runner->run($command, $env, null, self::TIMEOUT);

        return OneOffResult::fromOutcome($outcome->timedOut ? new ProcessOutcome(124, '', 'The migration did not finish within '.((int) self::TIMEOUT).' s.', true) : $outcome);
    }
}
