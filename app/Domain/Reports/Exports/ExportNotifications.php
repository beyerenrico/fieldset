<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationMessage;
use App\Domain\Notifications\Notifier;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Models\ReportExport;

/**
 * Tells the requesting user that an export finished or failed (§8.4 "notifies the user", §8.9
 * `export_ready`). The in-app notification is Filament's database notification with the download link as
 * its action (the completion pattern of Filament exports); email follows the user's preferences.
 */
final readonly class ExportNotifications
{
    public function __construct(private Notifier $notifier) {}

    public function ready(ReportExport $export, User $user, int $skipped = 0): void
    {
        $key = $export->format === ExportFormat::PdfZip && $skipped > 0 ? 'ready_partial' : 'ready';
        $this->send($export, $user, $key, ['rows' => $export->successful_rows, 'count' => $export->successful_rows, 'skipped' => $skipped], self::downloadUrl($export), null, 'reports.notifications.download');
    }

    /** A failure looks like one: danger colour and icon, no link (review U5). */
    public function failed(ReportExport $export, User $user): void
    {
        $this->send($export, $user, 'failed', [], null, 'danger');
    }

    /** Admin download route (session + ReportExportPolicy); answers 410 once the file expired. */
    public static function downloadUrl(ReportExport $export): ?string
    {
        $slug = Organization::query()->whereKey($export->organization_id)->value('slug');

        return is_string($slug) ? route('admin.exports.download', ['organization' => $slug, 'export' => $export->id]) : null;
    }

    /** @param  array<string, int>  $params */
    private function send(ReportExport $export, User $user, string $key, array $params, ?string $url, ?string $status = null, ?string $actionKey = null): void
    {
        $this->notifier->send(new NotificationMessage(
            NotificationEvent::ExportReady,
            "reports.notifications.{$key}.title",
            "reports.notifications.{$key}.body",
            [
                ...$params,
                'form' => $export->form->name ?? '',
                'format' => strtoupper($export->format === ExportFormat::PdfZip ? 'pdf' : $export->format->value),
                'days' => max(1, (int) config('reports.exports.keep_days', 7)),
            ],
            $url,
            $export->organization_id,
            null,
            $status,
            $actionKey,
        ), [$user]);
    }
}
