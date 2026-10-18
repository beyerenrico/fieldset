<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Docker\ImageInfo;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;

/**
 * Registry reachability (R10) for the images the instance still has to pull. Images already on the host need no
 * registry; a loopback mirror (the release harness, FSA_REGISTRY=127.0.0.1:…) is reached by the engine, not by
 * this container, so the pull is its check. An unreachable registry warns: the pull phase decides. The first
 * answer's `Date` header is the clock check's reference.
 */
final readonly class RegistryCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'registry';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $missing = array_values(array_filter($context->images, static fn (string $image): bool => ! ($context->docker->inspectImage($image) instanceof ImageInfo)));
        if ($missing === []) {
            return CheckResult::ok($this->key(), 'Every image is already on this host.', ['missing' => []]);
        }
        $unreachable = [];
        $reached = [];
        foreach (array_unique(array_map(self::registryOf(...), $missing)) as $registry) {
            if ($this->isLoopback($registry)) {
                $reached[] = $registry;

                continue;
            }
            $response = $context->probes->head('https://'.($registry === 'docker.io' ? 'registry-1.docker.io' : $registry).'/v2/');
            if ($response === null) {
                $unreachable[] = $registry;

                continue;
            }
            $reached[] = $registry;
            $context->registryDate ??= $response['date'];
        }
        $details = ['missing' => $missing, 'reachable' => $reached, 'unreachable' => $unreachable];
        if ($unreachable !== []) {
            return CheckResult::warn($this->key(), 'Cannot reach '.implode(', ', $unreachable).'.', 'Check the network, a proxy or FSA_REGISTRY; pulling the images will fail without it.', $details);
        }

        return CheckResult::ok($this->key(), 'Registries reachable: '.implode(', ', $reached).'.', $details);
    }

    /** "ghcr.io/x/app@sha256:…" → "ghcr.io"; a reference without a registry host is Docker Hub. */
    public static function registryOf(string $image): string
    {
        $first = explode('/', $image, 2)[0];

        return str_contains($image, '/') && (str_contains($first, '.') || str_contains($first, ':') || $first === 'localhost') ? $first : 'docker.io';
    }

    private function isLoopback(string $registry): bool
    {
        $host = preg_replace('/:\d+$/D', '', $registry);

        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_starts_with((string) $host, '127.');
    }
}
