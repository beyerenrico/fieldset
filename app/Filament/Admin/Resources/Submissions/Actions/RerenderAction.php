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
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Re-render PDF" in the inbox detail (§8.5, M3-T04): queues a new rendition with the submission's
 * pinned design version (RenditionPolicy::rerender). Existing renditions are never changed. Throttled per
 * submission and per user (documents.rerender.*), since every render is a Gotenberg call and a new file.
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
                $wait = self::throttle($record, $user);
                if ($wait > 0) {
                    Notification::make()->warning()->title(__('inbox.documents.rerender_throttled', ['seconds' => $wait]))->send();

                    return;
                }
                GenerateRendition::dispatch($record->uuid, $record->organization_id, Rendition::REASON_RERENDER, $user->id);

                Notification::make()->success()->title(__('inbox.documents.rerender_queued'))->send();
            });
    }

    /** Seconds to wait, or 0 after counting this re-render against both limits. */
    private static function throttle(Submission $submission, User $user): int
    {
        $limits = [
            'rerender:submission:'.$submission->uuid => max(1, (int) config('documents.rerender.per_submission_per_minute', 1)),
            'rerender:user:'.$user->id => max(1, (int) config('documents.rerender.per_user_per_minute', 10)),
        ];
        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return max(1, RateLimiter::availableIn($key));
            }
        }
        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 60);
        }

        return 0;
    }
}
