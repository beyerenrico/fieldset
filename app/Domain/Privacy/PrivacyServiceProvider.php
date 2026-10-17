<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use App\Domain\Privacy\Console\ApplyRetentionCommand;
use App\Domain\Privacy\Http\DataExportDownloadController;
use App\Domain\Privacy\Models\DataExport;
use App\Http\Middleware\Security\AdminSessionTimeout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Console\PruneCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * GDPR wiring (BUILD_PROMPT §11 Privacy, ADR 0039): the daily retention run, pruning of expired data exports
 * and the signed download route of data exports (user data ZIP, audit log CSV).
 */
final class PrivacyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ApplyRetentionCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('privacy:apply-retention')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
            // Models outside app/Models are not discovered by model:prune.
            $schedule->command(PruneCommand::class, ['--model' => [DataExport::class]])->daily()->withoutOverlapping()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', AdminSessionTimeout::class, 'signed', 'throttle:60,1'])
                ->get('admin/{organization}/data-exports/{export}', DataExportDownloadController::class)
                ->name('privacy.exports.download');
        }
    }
}
