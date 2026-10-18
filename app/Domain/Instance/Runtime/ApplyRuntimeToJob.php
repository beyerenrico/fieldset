<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Before every job (queued notifications and mails included), re-read the runtime state and point the URL root at
 * the public URL, so signed links and mail buttons use the current instance URL or tunnel without a worker restart
 * (R56). A sync job inside an HTTP request keeps the request's own resolution.
 */
final readonly class ApplyRuntimeToJob
{
    public function __construct(private Container $container, private RuntimeStateReader $reader, private RuntimeUrl $urls) {}

    public function handle(JobProcessing $event): void
    {
        $state = $this->reader->current();
        if (! $state instanceof RuntimeState || ($event->connectionName === 'sync' && $this->insideResolvedRequest())) {
            return;
        }
        $this->urls->applyToJob($state, CarbonImmutable::now());
    }

    private function insideResolvedRequest(): bool
    {
        $request = $this->container->bound('request') ? $this->container->make('request') : null;

        return $request instanceof Request && ResolvedRequest::of($request) instanceof ResolvedRequest;
    }
}
