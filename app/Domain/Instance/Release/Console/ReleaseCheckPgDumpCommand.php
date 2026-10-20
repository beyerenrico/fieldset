<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release\Console;

use App\Domain\Instance\Release\ManifestSchema;
use App\Domain\Instance\Release\PgDumpCompatibility;
use App\Domain\Instance\Release\ReleaseException;
use App\Domain\Instance\Release\ReleaseManifest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * R39's release check (plan U22, ADR 0091), run by the release workflow after `release:manifest` and by the local
 * harness: fails when the app image's pg_dump major is below the manifest's Postgres major. Prints one JSON document
 * with the version found per platform.
 */
#[Description('Fail when the app image\'s pg_dump major is below the release manifest\'s Postgres major (R39)')]
#[Signature('release:check-pg-dump
        {manifest : The release manifest, e.g. dist/release-manifest.json}
        {--mirror= : Pull the app image from this registry instead of its own, e.g. 127.0.0.1:5000 (local harness)}
        {--platform=* : Platform to run the image on (default: every platform of the manifest)}')]
final class ReleaseCheckPgDumpCommand extends Command
{
    public function handle(PgDumpCompatibility $compatibility): int
    {
        $path = $this->argument('manifest');
        $json = is_string($path) && is_file($path) ? @file_get_contents($path) : false;
        if ($json === false) {
            $this->components->error(sprintf('Cannot read the manifest [%s].', is_string($path) ? $path : ''));

            return self::FAILURE;
        }
        $mirror = $this->option('mirror');
        /** @var list<string> $platforms */
        $platforms = array_values(array_filter((array) $this->option('platform'), static fn (mixed $p): bool => is_string($p) && $p !== ''));

        try {
            $manifest = ReleaseManifest::fromJson($json, ManifestSchema::bundled());
            $results = $compatibility->check($manifest, is_string($mirror) && $mirror !== '' ? $mirror : null, $platforms);
        } catch (ReleaseException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->output->writeln(json_encode(['version' => (string) $manifest->version, 'pg_dump' => $results], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
