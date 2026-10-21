<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

use App\Domain\Instance\Docker\CommandRunner;

/**
 * R39's release check (plan U22, ADR 0091): the app image's `pg_dump` must not be older than the manifest's Postgres
 * major, or every backup of an instance on that release fails ("server version mismatch"; pg_dump refuses to dump a newer
 * server). The client version comes from the image itself, run once per platform with `pg_dump --version` as its entry
 * point (no app code, no network, no mounts); the server major from the postgres pin's tag (`16.15-alpine` → 16).
 * Docker's classic image store keeps one platform per by-digest reference, so an image this check pulled is removed
 * after each platform; one that was local before is kept.
 * Release tooling like `release:manifest`, not a `fieldset` path: it runs in CI and the local harness, never on an instance.
 */
final readonly class PgDumpCompatibility
{
    private const float TIMEOUT = 600.0;

    public function __construct(private CommandRunner $runner) {}

    /**
     * @param  list<string>  $platforms  the platforms to run the image on; empty for every platform the manifest lists
     * @return list<array{platform: string, image: string, pg_dump: string, client_major: int, server_major: int}>
     *
     * @throws ReleaseException when the image cannot run, prints no pg_dump version, or its major is below the server's
     */
    public function check(ReleaseManifest $manifest, ?string $mirror = null, array $platforms = []): array
    {
        $server = self::serverMajor($manifest->images['postgres']->pin);
        $image = $manifest->images['app']->pin->pullReference($mirror);
        $pulled = ! $this->runner->run(['docker', 'image', 'inspect', '--format', '{{.Id}}', $image])->successful();
        $results = [];
        foreach ($platforms === [] ? $manifest->platforms : $platforms as $platform) {
            try {
                $version = $this->clientVersion($image, $platform);
            } finally {
                if ($pulled) {
                    $this->runner->run(['docker', 'image', 'rm', $image]);
                }
            }
            $client = self::clientMajor($version);
            if ($client < $server) {
                throw new ReleaseException(sprintf(
                    'The app image\'s pg_dump %d (%s) is older than the manifest\'s Postgres %d: backups of this release would fail (R39). Install postgresql-client-%d in docker/app/Dockerfile.',
                    $client, $platform, $server, $server,
                ));
            }
            $results[] = ['platform' => $platform, 'image' => $image, 'pg_dump' => $version, 'client_major' => $client, 'server_major' => $server];
        }

        return $results;
    }

    /** The major of a postgres pin's tag: `16.15-alpine`, `16-alpine` and `16` give 16. */
    public static function serverMajor(ImagePin $postgres): int
    {
        if (preg_match('/^(\d+)(?:[.-]|$)/', $postgres->tag, $match) !== 1) {
            throw new ReleaseException(sprintf('The postgres image tag "%s" names no major version (expected e.g. 16.15-alpine).', $postgres->tag));
        }

        return (int) $match[1];
    }

    /** The major of `pg_dump --version` output: `pg_dump (PostgreSQL) 16.10 (Debian 16.10-1.pgdg13+1)` gives 16. */
    public static function clientMajor(string $version): int
    {
        if (preg_match('/^pg_dump \(PostgreSQL\) (\d+)(?:\.\d+)*/m', $version, $match) !== 1) {
            throw new ReleaseException(sprintf('The app image printed no pg_dump version: "%s".', mb_substr(trim($version), 0, 200)));
        }

        return (int) $match[1];
    }

    private function clientVersion(string $image, string $platform): string
    {
        $outcome = $this->runner->run(
            ['docker', 'run', '--rm', '--platform', $platform, '--network', 'none', '--entrypoint', 'pg_dump', $image, '--version'],
            timeout: self::TIMEOUT,
        );
        if (! $outcome->successful()) {
            throw new ReleaseException(sprintf(
                'Cannot run pg_dump --version in %s (%s): %s',
                $image, $platform, $outcome->timedOut ? 'timed out' : ($outcome->stderrTail(5) ?: "exit {$outcome->exitCode}"),
            ));
        }

        return trim($outcome->stdout);
    }
}
