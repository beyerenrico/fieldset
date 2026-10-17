<?php

declare(strict_types=1);

namespace App\Support\Logging;

/**
 * A Sentry DSN (`https://<public key>@<host>[:port][/<path>]/<project id>`), also used by self-hosted
 * Sentry-compatible servers such as GlitchTip. Only the public key is used; secret keys are ignored.
 */
final readonly class SentryDsn
{
    private function __construct(
        public string $publicKey,
        public string $envelopeUrl,
        public string $dsn,
    ) {}

    public static function tryParse(mixed $dsn): ?self
    {
        $parts = is_string($dsn) && $dsn !== '' ? parse_url($dsn) : false;
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || ($parts['user'] ?? '') === '' || ($parts['host'] ?? '') === '') {
            return null;
        }

        $segments = explode('/', trim($parts['path'] ?? '', '/'));
        $project = array_pop($segments);
        if ($project === null || preg_match('/^\d+$/', $project) !== 1) {
            return null;
        }

        $base = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $prefix = $segments === [] ? '' : '/'.implode('/', $segments);

        return new self($parts['user'], "{$base}{$prefix}/api/{$project}/envelope/", $dsn);
    }

    public function authHeader(string $client): string
    {
        return "Sentry sentry_version=7, sentry_client={$client}, sentry_key={$this->publicKey}";
    }
}
