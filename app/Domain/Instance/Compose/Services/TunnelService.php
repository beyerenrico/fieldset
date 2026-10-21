<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose\Services;

use App\Domain\Instance\Compose\ImageRole;
use App\Domain\Instance\Compose\RenderContext;
use App\Domain\Instance\Compose\ServiceDefinition;
use App\Domain\Instance\Compose\ServiceSpec;
use App\Domain\Instance\Compose\TunnelNetwork;
use App\Domain\Instance\State\InvalidState;
use App\Domain\Instance\Tunnel\HarnessTunnel;

/**
 * The quick tunnel (E11, R20, plan KTD12; ADR 0057, ADR 0082): the official cloudflared image, pinned by digest, in
 * account-less quick-tunnel mode, rendered only while `phone_https` is `tunnel` (local mode).
 *
 * - On its own network with a fixed address, which the app treats as the TCP peer of every tunnel request (it pins
 *   host and scheme for them; cloudflared is no trusted proxy, ADR 0082); it reaches the app there directly
 *   (`--url http://app:8080`), never through the edge.
 * - `restart: "no"`: every restart creates a new random hostname, which runtime.json would not know. A stopped tunnel is
 *   removed by the next mutating `fieldset` command (TunnelSweeper), never restarted.
 * - The metrics server listens on the tunnel network only, so `fieldset tunnel start` reads the hostname from `/quicktunnel`
 *   with a one-off on that network (QuickTunnelHostname). Nothing is published.
 * - No secret and no env at all; non-root (the image's 65532), read-only root, every capability dropped.
 * - The release-harness journeys swap in their fake tunnel image (HarnessTunnel, harness builds only).
 */
final readonly class TunnelService implements ServiceDefinition
{
    public const string NAME = 'tunnel';

    /** cloudflared's metrics server (`/quicktunnel`, `/ready`), on the tunnel network. */
    public const int METRICS_PORT = 20241;

    /** The image's own non-root UID/GID. */
    public const string USER = '65532:65532';

    public function specs(RenderContext $context): array
    {
        if (! $context->settings->tunnel()) {
            return [];
        }
        $subnet = $context->settings->tunnelSubnet() ?? throw new InvalidState('The quick tunnel needs the setting [tunnel_subnet].');

        return [new ServiceSpec(self::NAME, [
            'image' => HarnessTunnel::image() ?? $context->image(ImageRole::Cloudflared),
            // The image's entrypoint is `cloudflared --no-autoupdate`.
            'command' => ['tunnel', '--metrics', '0.0.0.0:'.self::METRICS_PORT, '--url', 'http://'.AppRoles::APP.':'.AppRoles::PORT],
            'user' => self::USER,
            'read_only' => true,
            'tmpfs' => ['/tmp'],
            'cap_drop' => ['ALL'],
            'security_opt' => ['no-new-privileges:true'],
            'networks' => [TunnelNetwork::NAME => ['ipv4_address' => TunnelNetwork::peerAddress($subnet)]],
            'depends_on' => [AppRoles::APP => ['condition' => 'service_healthy']],
            'restart' => 'no',
            'healthcheck' => ['disable' => true],
        ], [], networks: [TunnelNetwork::NAME => ['ipam' => ['config' => [TunnelNetwork::ipam($subnet)]]]])];
    }
}
