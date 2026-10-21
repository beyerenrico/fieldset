<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Helpers for J7 (plan U18, ADR 0086): the target releases J7 derives from the installed one, an API client that keeps
 * writing submissions while an upgrade runs (AE7), and the comparison of the live database with a safety backup's dump,
 * restored into a scratch database `fieldset_test_j07_cmp` of the instance's own Postgres and dropped again.
 */
final class UpgradeJourney
{
    /** A migration the happy target adds: its table exists after the upgrade and is gone after `fieldset rollback`. */
    public const string MARKER_MIGRATION = '2099_01_01_000000_fieldset_test_upgrade_marker.php';

    public const string MARKER_TABLE = 'fieldset_test_upgrade_marker';

    /** @return array{0: string, 1: array<string, string>} Dockerfile lines and files of the happy target (a migration) */
    public static function migratingTarget(): array
    {
        $migration = "<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\DB;\n\n"
            ."return new class extends Migration\n{\n    public function up(): void\n    {\n        DB::statement('CREATE TABLE ".self::MARKER_TABLE." (id bigserial PRIMARY KEY, note text)');\n"
            .'        DB::statement("INSERT INTO '.self::MARKER_TABLE." (note) VALUES ('migrated by the upgrade child')\");\n    }\n};\n";

        return ['COPY --chown=www-data:www-data '.self::MARKER_MIGRATION.' /var/www/html/database/migrations/'.self::MARKER_MIGRATION, [self::MARKER_MIGRATION => $migration]];
    }

    /** @return array{0: string, 1: array<string, string>} a target whose app answers 503 to everything, its health check included */
    public static function unhealthyTarget(): array
    {
        return ['COPY --chown=www-data:www-data index.php /var/www/html/public/index.php', ['index.php' => "<?php\nhttp_response_code(503);\necho 'fieldset-test: this release is unhealthy on purpose';\n"]];
    }

    /** @return array{0: string, 1: array<string, string>} a target whose `fieldset` entry point crashes (a segmentation fault) */
    public static function crashingTarget(): array
    {
        return ['COPY --chmod=0755 fieldset /usr/local/bin/fieldset', ['fieldset' => "#!/bin/sh\necho \"fieldset-test: this release's fieldset crashes on purpose\" >&2\nkill -SEGV \$\$\n"]];
    }

    /**
     * An integration token of the demo owner for the demo organization, with `submissions:write`, and a published form
     * version to write to (written straight into the database: an API client of the instance).
     *
     * @return array{token: string, organization: string, version: string}
     */
    public static function writer(string $uuid): array
    {
        $owner = RestoreJourney::sql($uuid, "SELECT id FROM users WHERE email = 'owner@demo.test'");
        $organization = RestoreJourney::sql($uuid, "SELECT id FROM organizations WHERE slug = 'demo'");
        $version = RestoreJourney::sql($uuid, "SELECT v.id FROM form_versions v JOIN forms f ON f.id = v.form_id WHERE f.organization_id = {$organization} AND v.published_at IS NOT NULL ORDER BY v.id LIMIT 1");
        $plain = Str::random(40);
        $id = RestoreJourney::sql($uuid, "INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, organization_id, name, token, abilities, created_at, updated_at) VALUES ('App\\Domain\\Identity\\Models\\User', {$owner}, {$organization}, 'fieldset-test-j07', '".hash('sha256', $plain)."', '[\"submissions:write\"]', now(), now()) RETURNING id");

        return ['token' => trim(explode("\n", $id)[0]).'|'.$plain, 'organization' => $organization, 'version' => $version];
    }

    /**
     * One draft written through the API (`PUT /api/v1/submissions/<uuid>`): the status, 0 when nothing answered.
     *
     * @param  array{token: string, organization: string, version: string}  $writer
     */
    public static function write(Client $http, array $writer, string $submission, ?string $host = null): int
    {
        try {
            return $http->put('/api/v1/submissions/'.$submission, [
                'headers' => ['Authorization' => 'Bearer '.$writer['token'], 'X-Organization-Id' => $writer['organization'], 'Accept' => 'application/json', ...($host === null ? [] : ['Host' => $host])],
                'json' => ['form_version_id' => (int) $writer['version'], 'data' => (object) []], 'timeout' => 10, 'connect_timeout' => 3,
            ])->getStatusCode();
        } catch (GuzzleException) {
            return 0;
        }
    }

    /** The submissions of these UUIDs the live database holds. */
    public static function present(string $uuid, array $submissions): int
    {
        if ($submissions === []) {
            return 0;
        }

        return (int) RestoreJourney::sql($uuid, "SELECT count(*) FROM submissions WHERE uuid::text IN ('".implode("','", $submissions)."')");
    }

    /** A digest of the data a rollback must bring back exactly: submissions (but `$except`) and accounts. */
    public static function digest(string $uuid, ?string $except = null, string $database = 'fieldset'): string
    {
        $where = $except === null ? '' : " WHERE uuid::text <> '{$except}'";

        return RestoreJourney::sql($uuid, "SELECT md5(coalesce((SELECT string_agg(uuid::text || ':' || revision || ':' || status, ',' ORDER BY uuid) FROM submissions{$where}), '') || '|' || coalesce((SELECT string_agg(id || ':' || email || ':' || password, ',' ORDER BY id) FROM users), ''))", $database);
    }

    /** The same digest of a safety backup's dump, restored into a scratch database of the instance's Postgres. */
    public static function backupDigest(string $uuid, string $backup, ?string $except = null): string
    {
        $postgres = RestoreJourney::container($uuid, 'postgres');
        RestoreJourney::docker(['cp', $backup.'/database.dump', $postgres.':/tmp/fieldset-test-j07.dump']);
        RestoreJourney::docker(['exec', $postgres, 'dropdb', '-U', 'fieldset', '--if-exists', 'fieldset_test_j07_cmp']);
        RestoreJourney::docker(['exec', $postgres, 'createdb', '-U', 'fieldset', 'fieldset_test_j07_cmp']);
        $restore = RestoreJourney::docker(['exec', $postgres, 'pg_restore', '-U', 'fieldset', '-d', 'fieldset_test_j07_cmp', '--no-owner', '/tmp/fieldset-test-j07.dump'], timeout: 600);

        try {
            return $restore->isSuccessful() ? self::digest($uuid, $except, 'fieldset_test_j07_cmp') : 'pg_restore failed: '.$restore->getErrorOutput();
        } finally {
            RestoreJourney::docker(['exec', $postgres, 'dropdb', '-U', 'fieldset', '--if-exists', 'fieldset_test_j07_cmp']);
            RestoreJourney::docker(['exec', $postgres, 'rm', '-f', '/tmp/fieldset-test-j07.dump']);
        }
    }

    /** The image reference the instance's container of a role runs. */
    public static function image(string $uuid, string $role): string
    {
        return trim(RestoreJourney::docker(['inspect', '-f', '{{.Config.Image}}', RestoreJourney::container($uuid, $role)])->getOutput());
    }

    /** Running containers of the instance with a role label (the detached supervisor, the foreground ops container). */
    public static function running(string $label, string $value, ?string $uuid = null): array
    {
        $filters = ['--filter', "label={$label}={$value}", ...($uuid === null ? [] : ['--filter', "label=app.fieldset.instance={$uuid}"])];

        return array_values(array_filter(explode("\n", trim(RestoreJourney::docker(['ps', '-q', ...$filters])->getOutput()))));
    }

    /** The foreground ops containers of one instance (role `ops`, the instance directory mounted): no one else's. */
    public static function opsContainers(string $dir): array
    {
        return array_values(array_filter(explode("\n", trim(RestoreJourney::docker(['ps', '-q', '--filter', 'label=app.fieldset.role=ops', '--filter', 'volume='.$dir])->getOutput()))));
    }

    /** Waits (polling) until `$done` holds or `$seconds` passed; returns whether it held. */
    public static function await(callable $done, int $seconds, int $every = 3): bool
    {
        $deadline = time() + $seconds;
        while (time() < $deadline) {
            if ($done()) {
                return true;
            }
            sleep($every);
        }

        return (bool) $done();
    }

    /** Starts a process (the shim) without waiting for it. */
    public static function start(Process $process): Process
    {
        $process->start();

        return $process;
    }
}
