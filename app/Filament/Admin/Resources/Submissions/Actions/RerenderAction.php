<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Actions;

use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Renditions\GenerateRendition;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * "Re-render PDF" in the inbox detail (§8.5, M3-T04): queues a new rendition with the submission's
 * pinned design version (RenditionPolicy::rerender). Existing renditions are never changed.
 */
final class RerenderAction
{
    public static function make(): Action
    {
        return Action::make('rerender')->label(__('inbox.documents.rerender'))->icon('heroicon-o-arrow-path')->color('gray')
            ->authorize(fn (Submission $record): bool => $record->status !== SubmissionStatus::Draft
                && (auth()->user()?->can('rerender', [Rendition::class, $record]) ?? false))
            ->requiresConfirmation()
            ->modalDescription(__('inbox.documents.rerender_help'))
            ->action(function (Submission $record): void {
                /** @var User $user */
                $user = auth()->user();
                GenerateRendition::dispatch($record->uuid, $record->organization_id, Rendition::REASON_RERENDER, $user->id);

                Notification::make()->success()->title(__('inbox.documents.rerender_queued'))->send();
            });
    }
}
