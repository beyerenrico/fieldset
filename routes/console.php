<?php

declare(strict_types=1);

use App\Domain\Documents\Renditions\SweepMissingRenditions;
use App\Domain\Mail\CustomerSend\FailVoidedSends;
use App\Domain\Mail\Models\DocumentLink;
use App\Domain\Reports\Exports\ExportSweep;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\Scheduling\ScheduledReports;
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

// --- M5 reports (BUILD_PROMPT §8.4) ---
// Export files expire after reports.exports.keep_days; stuck exports are failed; scheduled report emails go out hourly when due.
Schedule::command(PruneCommand::class, ['--model' => [ReportExport::class]])->daily()->withoutOverlapping();
Artisan::command('reports:send-scheduled', function (ScheduledReports $reports): void {
    $this->info(sprintf('Queued %d scheduled report email(s).', $reports->sendDue()));
})->purpose('Send weekly and monthly saved report emails that are due');
Schedule::command('reports:send-scheduled')->hourly()->withoutOverlapping()->onOneServer();
Artisan::command('reports:sweep-exports', function (ExportSweep $sweep): void {
    $this->info(sprintf('Failed %d stuck export(s).', $sweep->handle()));
})->purpose('Fail exports whose worker died and remove leftover temp files');
Schedule::command('reports:sweep-exports')->everyTenMinutes()->withoutOverlapping()->onOneServer();
// --- end M5 reports ---

// --- M7 SSO (ADR 0036) ---
// Provider health (discovery, JWKS, SAML metadata) hourly; DNS re-check of verified domains daily.
Schedule::command('sso:check-providers')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('sso:recheck-domains')->daily()->withoutOverlapping()->onOneServer();
// --- end M7 SSO ---

// Integration tokens (ADR 0043): drop tokens that expired more than a day ago.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily()->withoutOverlapping()->onOneServer();
