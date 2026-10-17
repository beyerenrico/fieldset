<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

/**
 * Builds the release manifest: the app image just pushed plus every committed third-party pin, each resolved
 * per platform in the registry (a pin the registry does not hold fails), asset checksums, and a schema check
 * of the result (R2, R4, KTD22).
 */
final readonly class ReleaseManifestBuilder
{
    public function __construct(
        private ImageInspector $inspector,
        private ManifestSchema $schema,
    ) {}

    public function build(ReleaseRequest $request): ReleaseManifest
    {
        if ($request->mirror !== null) {
            ImagePin::assertRegistry($request->mirror);
        }

        $pins = ['app' => $request->inputs->appImage($request->version, $request->appDigest), ...$request->inputs->images];
        $images = [];
        foreach ($pins as $service => $pin) {
            $images[$service] = new ManifestImage($pin, $this->inspector->inspect($pin, $request->platforms, $request->mirror));
        }

        $manifest = new ReleaseManifest(
            $request->version,
            $request->upgradesFrom ?? $request->inputs->upgradesFrom,
            $request->inputs->notesUrl($request->version),
            $request->platforms,
            $images,
            $this->assets($request->assets),
        );
        $this->schema->assertValid($manifest->toJson());

        return $manifest;
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, array{sha256: string, size: int}>
     */
    private function assets(array $paths): array
    {
        $assets = [];
        foreach ($paths as $path) {
            $name = basename($path);
            $hash = is_file($path) ? hash_file('sha256', $path) : false;
            $size = $hash === false ? false : filesize($path);
            if ($hash === false || $size === false) {
                throw new ReleaseException(sprintf('Cannot read the release asset "%s".', $path));
            }

            if (array_key_exists($name, $assets)) {
                throw new ReleaseException(sprintf('Two release assets are named "%s".', $name));
            }

            $assets[$name] = ['sha256' => $hash, 'size' => $size];
        }

        ksort($assets);

        return $assets;
    }
}
