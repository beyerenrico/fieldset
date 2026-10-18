<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use App\Domain\Instance\Release\ReleaseVersion;

/** A release published into the local harness by ReleaseHarness::publish(). */
final readonly class HarnessRelease
{
    /**
     * @param  string  $directory  dist/download/v<version>/ on the host
     * @param  string  $url  the same directory on the release server
     * @param  list<string>  $platforms
     */
    public function __construct(
        public ReleaseVersion $version,
        public string $appDigest,
        public string $appReference,
        public string $directory,
        public string $url,
        public array $platforms,
    ) {}

    /** @return array<string, string> DOCKER_DEFAULT_PLATFORM when the release has to run emulated (ReleaseHarness) */
    public function platformEnvironment(): array
    {
        return ReleaseHarness::platformEnvironment($this->platforms);
    }

    public function manifestPath(): string
    {
        return "{$this->directory}/".ReleaseHarness::MANIFEST;
    }

    public function bundlePath(): string
    {
        return $this->manifestPath().'.sigstore.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $data = json_decode((string) file_get_contents($this->manifestPath()), true);

        return is_array($data) ? $data : [];
    }
}
