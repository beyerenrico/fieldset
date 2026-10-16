<?php

declare(strict_types=1);

use App\Domain\Submissions\Models\IdempotencyKey;
use Illuminate\Database\Console\PruneCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Models outside app/Models are not discovered by model:prune, so they are listed explicitly.
Schedule::command(PruneCommand::class, ['--model' => [IdempotencyKey::class]])->hourly()->withoutOverlapping();
