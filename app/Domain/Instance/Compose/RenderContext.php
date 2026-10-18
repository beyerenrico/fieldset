<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\InvalidState;

/**
 * What a ServiceDefinition renders from: the validated state, its settings and budget, and the secrets. `emulated`:
 * the images run as another architecture than the engine's (DOCKER_DEFAULT_PLATFORM, ADR 0070); every memory limit
 * is then doubled, because the E16 budget is for native images and each emulated process carries its translator
 * (amd64 mailpit on Apple Silicon: about 57 MiB instead of 13, so its 48 MiB limit killed it).
 */
final readonly class RenderContext
{
    public const int EMULATION_MEMORY_FACTOR = 2;

    public InstanceSettings $settings;

    public ResourceBudget $budget;

    public function __construct(
        public InstanceState $state,
        public InstanceSecrets $secrets,
        public bool $emulated = false,
    ) {
        $this->settings = InstanceSettings::of($state);
        $this->budget = ResourceBudget::forSize($this->settings->size());
    }

    /** Compose `mem_limit` of a service: its budget, doubled under emulation. */
    public function memoryLimit(string $service): string
    {
        return ($this->budget->limitMib($service) * ($this->emulated ? self::EMULATION_MEMORY_FACTOR : 1)).'m';
    }

    public function mode(): string
    {
        return $this->state->mode();
    }

    /** The pinned `repository@sha256:…` of an image role; a state without it cannot be rendered (exit 3). */
    public function image(ImageRole $role): string
    {
        return $this->state->digests()[$role->value]
            ?? throw new InvalidState("The instance state pins no image digest for [{$role->value}]; run `fsa upgrade` or reinstall from a release manifest.");
    }

    /** @return array<string, string> */
    public function labels(?string $role = null): array
    {
        return Labels::forInstance($this->state->uuid(), $role);
    }

    /**
     * Compose long-syntax port published on loopback only.
     *
     * @return array{target: int, published: string, host_ip: string, protocol: string}
     */
    public static function loopbackPort(int $published, int $target): array
    {
        return ['target' => $target, 'published' => (string) $published, 'host_ip' => '127.0.0.1', 'protocol' => 'tcp'];
    }
}
