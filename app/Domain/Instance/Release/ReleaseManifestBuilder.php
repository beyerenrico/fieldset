<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

use App\Domain\Instance\Compose\StackFile;
use App\Domain\Instance\InstanceException;

/**
 * Builds the release manifest: the app image just pushed plus every committed third-party pin, each resolved
 * per platform in the registry (a pin the registry does not hold fails), asset checksums, and a schema check
 * of the result (R2, R4, KTD22). With a stack-file path it first renders the stack file from the same pins
 * (R18, ADR 0076), which then is one of the checksummed assets: its digests are the manifest's.
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
            $this->assets([...$request->assets, ...$this->stackFile($request, $pins)]),
        );
        $this->schema->assertValid($manifest->toJson());

        return $manifest;
    }

    /**
     * @param  array<string, ImagePin>  $pins
     * @return list<string> the stack file's path, when the request asks for one
     */
    private function stackFile(ReleaseRequest $request, array $pins): array
    {
        if ($request->stackFile === null) {
            return [];
        }

        try {
            StackFile::render(array_map(static fn (ImagePin $pin): string => $pin->pullReference(), $pins), (string) $request->version)->writeTo($request->stackFile);
        } catch (InstanceException $exception) {
            throw new ReleaseException($exception->getMessage(), 0, $exception);
        }

        return [$request->stackFile];
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
