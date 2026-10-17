<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Console\PruneAuditLogCommand;
use App\Domain\Audit\Console\SealAuditLogCommand;
use App\Domain\Audit\Console\VerifyAuditLogCommand;
use App\Filament\Admin\Resources\AuditLogs\Support\HistoryHook;
use App\Filament\Admin\Resources\AuditLogs\Widgets\RecordHistory;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Audit log wiring (BUILD_PROMPT §8.11, ADR 0038): retention pruning (daily), catch-up sealing of the hash
 * chain (every five minutes), the verify command, and the History section on key record pages.
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneAuditLogCommand::class, SealAuditLogCommand::class, VerifyAuditLogCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('audit:prune')->dailyAt('03:15')->withoutOverlapping()->onOneServer();
            $schedule->command('audit:seal')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
        });

        Livewire::component(HistoryHook::COMPONENT, RecordHistory::class);
        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_END, static fn (): ?View => HistoryHook::render(), scopes: HistoryHook::PAGES);
    }
}
