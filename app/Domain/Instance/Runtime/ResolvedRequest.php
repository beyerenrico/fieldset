<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use Illuminate\Http\Request;

/**
 * What the runtime middleware resolved for one request, kept on the request: the URL root links use, whether the
 * request counts as HTTPS (HSTS, Secure and `__Host-` cookies, upgrade-insecure-requests) and whether it came
 * through the tunnel.
 */
final readonly class ResolvedRequest
{
    public const string ATTRIBUTE = 'fieldset.runtime';

    public function __construct(
        public string $root,
        public bool $https,
        public bool $viaTunnel,
    ) {}

    public static function of(Request $request): ?self
    {
        $resolved = $request->attributes->get(self::ATTRIBUTE);

        return $resolved instanceof self ? $resolved : null;
    }
}
