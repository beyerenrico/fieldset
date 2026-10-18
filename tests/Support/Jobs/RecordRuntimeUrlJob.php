<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\URL;

/** Test job (plan U10): records the links a job generates and the app.url it runs with. */
final class RecordRuntimeUrlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var list<array{url: string, signed: string, app_url: mixed}> */
    public static array $seen = [];

    public function handle(): void
    {
        self::$seen[] = ['url' => url('/admin'), 'signed' => URL::signedRoute('field-app'), 'app_url' => config('app.url')];
    }
}
