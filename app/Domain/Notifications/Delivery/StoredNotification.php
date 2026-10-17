<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Delivery;

use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationMessage;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The in-app notification row (`notifications` table, §8.9). Its data is a Filament database
 * notification (format "filament": title/body as escaped HTML, an "open" action) so the admin bell
 * shows it, plus the fields the API presents: event, plain-text title/body, url, organization_id.
 * Sent synchronously from the queued DeliverNotification job.
 */
final class StoredNotification extends Notification
{
    /** Stable `notifications.type` value (independent of the class name); the API lists only these. */
    public const string TYPE = 'app.notification';

    /** @param  array<string, mixed>  $data */
    public function __construct(public readonly array $data)
    {
        $this->id = (string) Str::uuid7(); // time-ordered: ties on created_at still sort newest first
    }

    /** @return array<string, mixed> */
    public static function payload(NotificationMessage $message, RenderedNotification $rendered, ?int $organizationId): array
    {
        [$status, $icon] = self::style($message->event, $message->status);
        $actions = $rendered->url === null ? [] : [
            Action::make('open')->label(trans($message->actionKey ?? 'notifications.open', [], $rendered->locale))
                ->url($rendered->url)->markAsRead()->button(),
        ];

        $filament = FilamentNotification::make()
            ->title(e($rendered->title))
            ->body($rendered->body === '' ? null : e($rendered->body))
            ->status($status)
            ->icon($icon)
            ->actions($actions)
            ->getDatabaseMessage();

        return [
            ...$filament,
            'event' => $message->event->value,
            'text' => ['title' => $rendered->title, 'body' => $rendered->body],
            'url' => $rendered->url,
            'organization_id' => $organizationId,
            'submission_uuid' => $message->submissionUuid,
            'locale' => $rendered->locale,
        ];
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return self::TYPE;
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }

    /** @return array{0: string, 1: Heroicon} Filament status (color) and icon per event, or for an explicit "danger" */
    private static function style(NotificationEvent $event, ?string $status = null): array
    {
        if ($status === 'danger') {
            return ['danger', Heroicon::OutlinedExclamationTriangle];
        }

        return match ($event) {
            NotificationEvent::ReviewAssigned => ['info', Heroicon::OutlinedClipboardDocumentCheck],
            NotificationEvent::ChangesRequested => ['warning', Heroicon::OutlinedArrowUturnLeft],
            NotificationEvent::SubmissionApproved => ['success', Heroicon::OutlinedCheckCircle],
            NotificationEvent::WorkflowFailed => ['danger', Heroicon::OutlinedExclamationTriangle],
            NotificationEvent::FormAvailable => ['info', Heroicon::OutlinedDocumentPlus],
            NotificationEvent::WorkflowNotice => ['info', Heroicon::OutlinedChatBubbleLeftEllipsis],
            NotificationEvent::ExportReady => ['success', Heroicon::OutlinedArrowDownTray],
            NotificationEvent::SsoMisconfigured => ['danger', Heroicon::OutlinedShieldExclamation],
        };
    }
}
