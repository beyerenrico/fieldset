<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

/**
 * Where the mail catcher sits in domain mode (plan R66, KTD10, ADR 0075): the edge serves Mailpit's UI under
 * `MAIL_PATH` and asks the app at `AUTH_PATH` (Caddy `forward_auth`) whether the request may pass. Shared by the
 * edge's Caddyfile (ops side) and the app's route, so the two cannot drift. The auth path is not on the tunnel's
 * allow-list (TunnelPathPolicy), and the edge answers it with 404 from outside.
 */
final class CatcherRoute
{
    /** The catcher UI behind the edge; Mailpit runs with this webroot (MP_WEBROOT). */
    public const string MAIL_PATH = '/_mail/';

    /** The app endpoint `forward_auth` calls for every catcher request. */
    public const string AUTH_PATH = '/_fsa/catcher-auth';

    /** Path prefix the edge never forwards from outside (the auth endpoint lives below it). */
    public const string INTERNAL_PREFIX = '/_fsa/';
}
