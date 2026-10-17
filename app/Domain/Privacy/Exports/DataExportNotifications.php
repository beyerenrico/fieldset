<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Exports;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationMessage;
use App\Domain\Notifications\Notifier;
use App\Domain\Privacy\Models\DataExport;
use Illuminate\Support\Facades\URL;

/**
 * Tells the requester that a data export finished (with a signed link that expires with the file) or failed
 * (§8.9 `export_ready`, the M5 pattern).
 */
final readonly class DataExportNotifications
{
    public function __construct(private Notifier $notifier) {}

    public function ready(DataExport $export, User $user): void
    {
        $this->send($export, $user, 'ready', self::downloadUrl($export), null, 'privacy.notifications.download');
    }

    public function failed(DataExport $export, User $user): void
    {
        $this->send($export, $user, 'failed', null, 'danger', null);
    }

    /** Signed admin route (session + requester only), valid until the file expires. */
    public static function downloadUrl(DataExport $export): ?string
    {
        $slug = Organization::query()->whereKey($export->organization_id)->value('slug');
        if (! is_string($slug) || $export->expires_at === null) {
            return null;
        }

        return URL::temporarySignedRoute('privacy.exports.download', $export->expires_at, ['organization' => $slug, 'export' => $export->id]);
    }

    private function send(DataExport $export, User $user, string $key, ?string $url, ?string $status, ?string $actionKey): void
    {
        $this->notifier->send(new NotificationMessage(
            NotificationEvent::ExportReady,
            "privacy.notifications.{$key}_{$export->kind->value}.title",
            "privacy.notifications.{$key}_{$export->kind->value}.body",
            ['days' => DataExport::keepDays()],
            $url,
            $export->organization_id,
            null,
            $status,
            $actionKey,
        ), [$user]);
    }
}
