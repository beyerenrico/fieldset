<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release\Console;

use App\Domain\Instance\Release\ImageInspector;
use App\Domain\Instance\Release\ManifestSchema;
use App\Domain\Instance\Release\PlatformImage;
use App\Domain\Instance\Release\RegistryClient;
use App\Domain\Instance\Release\ReleaseException;
use App\Domain\Instance\Release\ReleaseInputs;
use App\Domain\Instance\Release\ReleaseManifestBuilder;
use App\Domain\Instance\Release\ReleaseRequest;
use App\Domain\Instance\Release\ReleaseVersion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Release tooling for CI and the local harness (plan U7, ADR 0067), not an `fsa` command: it needs no instance
 * directory. Registry credentials for the app image (a private GHCR package before it is made public) come from
 * RELEASE_REGISTRY_USERNAME and RELEASE_REGISTRY_PASSWORD, never from flags.
 */
#[Description('Build the signed-release manifest: every service image by digest with sizes, asset checksums')]
#[Signature('release:manifest
        {version : Release version or tag, e.g. 2.0.0 or v2.0.0-rc.1}
        {--app-digest= : Digest of the pushed multi-platform app image index (sha256:…)}
        {--inputs=distribution/release.json : Committed release inputs (third-party pins, app repository, oldest upgrade source)}
        {--platform=* : Platform every image must carry (default: linux/amd64 and linux/arm64)}
        {--asset=* : Release asset to checksum, e.g. distribution/install.sh (repeatable)}
        {--mirror= : Read every image from this registry instead of its own, e.g. 127.0.0.1:5000 (local harness)}
        {--upgrades-from= : Override the oldest version this release upgrades from}
        {--output= : Write the manifest to this file instead of stdout}')]
final class ReleaseManifestCommand extends Command
{
    public function handle(): int
    {
        try {
            $request = $this->request();
            $manifest = new ReleaseManifestBuilder(
                new ImageInspector(new RegistryClient($this->credentials($request->inputs->appRegistry))),
                ManifestSchema::bundled(),
            )->build($request);
        } catch (ReleaseException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $output = $this->stringOption('output');
        if ($output === null) {
            $this->output->write($manifest->toJson(), false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        if (@file_put_contents($output, $manifest->toJson()) === false) {
            $this->components->error("Cannot write the manifest to [{$output}].");

            return self::FAILURE;
        }

        $this->components->info(sprintf('Release manifest for %s (%s) written to [%s].', $manifest->version, $manifest->channel()->value, $output));

        return self::SUCCESS;
    }

    private function request(): ReleaseRequest
    {
        $version = $this->argument('version');
        $upgradesFrom = $this->stringOption('upgrades-from');
        /** @var list<string> $platforms */
        $platforms = array_values(array_filter((array) $this->option('platform'), is_string(...)));
        /** @var list<string> $assets */
        $assets = array_values(array_filter((array) $this->option('asset'), is_string(...)));

        return new ReleaseRequest(
            ReleaseVersion::fromInput(is_string($version) ? $version : ''),
            $this->stringOption('app-digest') ?? throw new ReleaseException('--app-digest is required (the pushed app image index digest).'),
            ReleaseInputs::fromFile($this->path($this->stringOption('inputs') ?? 'distribution/release.json')),
            $platforms === [] ? PlatformImage::SUPPORTED : $platforms,
            array_map($this->path(...), $assets),
            $this->stringOption('mirror'),
            $upgradesFrom === null ? null : ReleaseVersion::fromInput($upgradesFrom),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function credentials(string $registry): array
    {
        $username = getenv('RELEASE_REGISTRY_USERNAME');
        $password = getenv('RELEASE_REGISTRY_PASSWORD');

        return is_string($username) && $username !== '' && is_string($password) && $password !== ''
            ? [$registry => [$username, $password]]
            : [];
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
