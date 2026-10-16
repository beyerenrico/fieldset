<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SavedReports\Actions;

use App\Domain\Dashboards\Models\DashboardPin;
use App\Domain\Dashboards\Pins\DashboardPins;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Models\SavedReport;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Pin to dashboard" / "Unpin" for a saved report (§8.1 v1.0, ADR 0034), on the table and the report page.
 * Pinning needs SavedReportPolicy::view; unpinning one's own pin is always possible. The table selects a
 * per-row flag (withPinnedFlag) so visibility does not cost a query per row.
 */
final class PinActions
{
    public const string PINNED = 'pinned_by_viewer';

    public static function pin(): Action
    {
        return Action::make('pin')
            ->label(__('reports.saved.pin'))
            ->icon(Heroicon::OutlinedBookmark)
            ->color('gray')
            ->authorize('view')
            ->visible(fn (SavedReport $record): bool => ! self::isPinned($record))
            ->action(function (SavedReport $record): void {
                $pin = app(DashboardPins::class)->pin(self::user(), $record);
                $record->setAttribute(self::PINNED, $pin->id);
                Notification::make()->success()->title(__('reports.saved.pinned'))->send();
            });
    }

    public static function unpin(): Action
    {
        return Action::make('unpin')
            ->label(__('reports.saved.unpin'))
            ->icon(Heroicon::OutlinedBookmarkSlash)
            ->color('gray')
            ->visible(fn (SavedReport $record): bool => self::isPinned($record))
            ->action(function (SavedReport $record): void {
                app(DashboardPins::class)->unpin(self::user(), $record->id);
                $record->setAttribute(self::PINNED, null);
                Notification::make()->success()->title(__('reports.saved.unpinned'))->send();
            });
    }

    /**
     * Adds the viewer's pin id (or null) as `pinned_by_viewer` to each saved report row.
     *
     * @param  Builder<SavedReport>  $query
     * @return Builder<SavedReport>
     */
    public static function withPinnedFlag(Builder $query): Builder
    {
        return $query->addSelect([self::PINNED => DashboardPin::query()->select('dashboard_pins.id')
            ->whereColumn('dashboard_pins.saved_report_id', 'saved_reports.id')
            ->where('dashboard_pins.user_id', self::user()->id)
            ->limit(1)]);
    }

    private static function isPinned(SavedReport $record): bool
    {
        $attributes = $record->getAttributes();

        return array_key_exists(self::PINNED, $attributes)
            ? $attributes[self::PINNED] !== null
            : app(DashboardPins::class)->isPinned(self::user(), $record->id);
    }

    private static function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
