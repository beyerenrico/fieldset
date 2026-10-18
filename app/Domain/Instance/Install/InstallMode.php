<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\State\InstanceState;
use Closure;

/**
 * The mode an install creates (E7, ADR 0053, ADR 0075), decided in the doctor phase and journaled with it, so a
 * resumed install keeps it: local (default), or domain with `https://<domain>` as the instance URL, the ACME inputs
 * and the edge network's subnet (chosen once, never re-chosen while the state has one). The state phase applies it.
 */
final class InstallMode
{
    /**
     * @param  Closure(): list<string>  $takenSubnets  subnets of the engine's networks, asked only when one is needed
     * @return array{mode: string, url: string|null, settings: array<string, string>}
     */
    public static function forRequest(InstallRequest $request, ?InstanceState $state, string $uuid, Closure $takenSubnets): array
    {
        $domain = $request->domain();
        if ($domain === null) {
            return ['mode' => 'local', 'url' => null, 'settings' => []];
        }
        $subnet = $state?->setting('edge_subnet');

        return [
            'mode' => 'domain',
            'url' => 'https://'.$domain,
            'settings' => [
                'domain' => $domain,
                'edge_subnet' => is_string($subnet) ? $subnet : EdgeNetwork::choose($uuid, $takenSubnets()),
                ...$request->acme(),
            ],
        ];
    }

    /** @param  array<string, mixed>  $mode  the doctor phase's `mode` */
    public static function apply(InstanceState $state, array $mode): InstanceState
    {
        if (($mode['mode'] ?? 'local') === 'local') {
            return $state;
        }
        /** @var array<string, string> $settings */
        $settings = is_array($mode['settings'] ?? null) ? $mode['settings'] : [];

        return $state->withMode((string) $mode['mode'])
            ->withUrl(is_string($mode['url'] ?? null) ? $mode['url'] : null)
            ->withSettings($settings);
    }

    /**
     * What the doctor phase applied, keyed like InstallRequest, for a resume's flag check: an absent key was not
     * applied yet, a null one was applied as "not set".
     *
     * @param  array<string, mixed>  $doctor  the doctor phase's data
     * @return array<string, string|null>
     */
    public static function applied(array $doctor): array
    {
        $settings = is_array($doctor['mode']['settings'] ?? null) ? $doctor['mode']['settings'] : [];
        $keys = ['domain', ...InstallRequest::ACME_FLAGS];

        return array_map(static fn (string $key): ?string => is_string($settings[$key] ?? null) ? $settings[$key] : null, array_combine($keys, $keys));
    }
}
