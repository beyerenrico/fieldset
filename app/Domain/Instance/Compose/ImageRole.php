<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

/**
 * Keys of the image digests in the instance state and the release manifest (E14, plan U3/U7). Every service
 * runs from `InstanceState::digests()[role]`, never from a tag.
 */
enum ImageRole: string
{
    /** The app image: app, queue, scheduler, one-offs, ops and backup roles. */
    case App = 'app';

    /** postgres:16-alpine; the libc family must stay musl, as in 1.0 (KTD20). */
    case Postgres = 'postgres';

    /** valkey/valkey:8-alpine, the `redis` service. */
    case Valkey = 'valkey';

    case Gotenberg = 'gotenberg';

    case Mailpit = 'mailpit';

    /** The edge (U8). */
    case Caddy = 'caddy';

    /** The quick tunnel (U14). */
    case Cloudflared = 'cloudflared';
}
