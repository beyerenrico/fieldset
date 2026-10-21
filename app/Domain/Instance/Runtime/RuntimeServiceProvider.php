<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Instance runtime wiring (KTD11, KTD24, ADR 0074): the only instance code web and queue processes load. The
 * reader is a process-wide singleton, so a worker keeps its last good value across jobs (scoped instances are
 * reset per job). Nothing here touches the runtime file at boot; without FIELDSET_RUNTIME_PATH everything is a no-op.
 */
final class RuntimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RuntimeStateReader::class);
    }

    public function boot(): void
    {
        Event::listen(JobProcessing::class, [ApplyRuntimeToJob::class, 'handle']);
    }
}
