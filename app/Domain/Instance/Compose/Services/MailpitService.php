<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose\Services;

use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\Compose\ImageRole;
use App\Domain\Instance\Compose\RenderContext;
use App\Domain\Instance\Compose\ServiceDefinition;
use App\Domain\Instance\Compose\ServiceEnvironments;
use App\Domain\Instance\Compose\ServiceSpec;

/**
 * The mail catcher in capture mode (E8, KTD13, ADR 0054): generated UI credentials, a bounded message count and
 * its database on a volume. Its SMTP port is never published, only reachable from the stack; its UI is published
 * on loopback in local and proxy modes. Domain mode serves the UI under `/_mail/` behind the edge (U8, MP_WEBROOT). After
 * the switch to SMTP it stays, read-only (SMTP bound to the container's loopback), until `--remove-catcher` (U12,
 * ADR 0080); then it is not rendered and its volume is removed.
 */
final readonly class MailpitService implements ServiceDefinition
{
    public const string NAME = 'mailpit';

    public const string VOLUME = 'mailpit-data';

    public const int UI_PORT = 8025;

    public function specs(RenderContext $context): array
    {
        if (! $context->settings->runsCatcher()) {
            return [];
        }
        $ports = in_array($context->mode(), ['local', 'proxy'], true) ? ['ports' => [RenderContext::loopbackPort($context->settings->mailUiPort(), self::UI_PORT)]] : [];

        return [new ServiceSpec(self::NAME, [
            'image' => $context->image(ImageRole::Mailpit),
            ...$ports,
            'volumes' => [['type' => 'volume', 'source' => self::VOLUME, 'target' => dirname(ServiceEnvironments::MAILPIT_DATABASE)]],
            // Domain mode: the edge serves the UI under /_mail/ (MP_WEBROOT) and reaches Mailpit on its network.
            'networks' => $context->mode() === 'domain' ? ['default', EdgeNetwork::NAME] : ['default'],
        ], new ServiceEnvironments($context)->mailpit(), [self::VOLUME => []])];
    }
}
