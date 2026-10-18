<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

/**
 * A building block of the instance's compose project: one or more services for the current state (mode,
 * settings, size). Later units extend the renderer by adding definitions (edge U8, tunnel U14, backup U16) or by
 * branching on `RenderContext::mode()` inside one (proxy ports and networks, U9), never by editing the file.
 */
interface ServiceDefinition
{
    /** @return list<ServiceSpec> the services to render; empty when the state does not need them */
    public function specs(RenderContext $context): array;
}
