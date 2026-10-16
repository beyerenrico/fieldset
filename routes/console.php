<?php

declare(strict_types=1);

use App\Domain\Documents\Renditions\SweepMissingRenditions;
use App\Domain\Mail\CustomerSend\FailVoidedSends;
use App\Domain\Mail\Models\DocumentLink;
use App\Domain\Submissions\Models\IdempotencyKey;
use Illuminate\Database\Console\PruneCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Models outside app/Models are not discovered by model:prune, so they are listed explicitly.
Schedule::command(PruneCommand::class, ['--model' => [IdempotencyKey::class, DocumentLink::class]])->hourly()->withoutOverlapping();

// Safety net for documents (ADR 0031): renders that never ran are dispatched again; sends of voided submissions close.
Artisan::command('documents:sweep', function (SweepMissingRenditions $renders, FailVoidedSends $sends): void {
    $this->info(sprintf('Dispatched %d render(s); closed %d send request(s) of voided submissions.', $renders->handle(), $sends->handle()));
})->purpose('Re-dispatch missing renditions and close sends of voided submissions');
Schedule::command('documents:sweep')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
