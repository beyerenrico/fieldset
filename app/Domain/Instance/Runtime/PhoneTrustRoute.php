<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

/**
 * The phone trust page of local-CA mode (R19, ADR 0082), shared by the app's routes and the edge's Caddyfile: on plain
 * HTTP (port 80) the edge forwards only these paths to the app and answers everything else with 308 to HTTPS.
 */
final class PhoneTrustRoute
{
    /** The trust page: steps, fingerprint, trust check, QR code. */
    public const string PATH = '/phone-trust';

    /** The CA's public root certificate (never its key). */
    public const string ROOT_PATH = '/phone-trust/root.crt';
}
