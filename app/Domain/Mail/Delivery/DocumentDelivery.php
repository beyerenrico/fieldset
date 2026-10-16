<?php

declare(strict_types=1);

namespace App\Domain\Mail\Delivery;

use App\Domain\Mail\Models\DocumentLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Decides how documents are delivered by mail (§8.8): attached while the total stays within
 * messaging.attachment_max_bytes (default 10 MB), otherwise as an expiring download link
 * (messaging.links.ttl_days, default 14). Must run inside the tenant of the documents.
 */
final class DocumentDelivery
{
    public const int TOKEN_LENGTH = 48;

    public function plan(DeliverableDocument ...$documents): DeliveryPlan
    {
        $budget = (int) config('messaging.attachment_max_bytes');
        $attachments = [];
        $links = [];

        foreach ($documents as $document) {
            $file = DocumentFile::from($document);
            if ($file->size <= $budget) {
                $attachments[] = $file;
                $budget -= $file->size;

                continue;
            }
            $links[] = $this->createLink($file);
        }

        return new DeliveryPlan($attachments, $links);
    }

    public function createLink(DeliverableDocument $document, ?int $ttlDays = null): DeliveredLink
    {
        $file = DocumentFile::from($document);
        $token = Str::random(self::TOKEN_LENGTH);
        $expiresAt = CarbonImmutable::now()->addDays($ttlDays ?? (int) config('messaging.links.ttl_days'));

        $link = DocumentLink::query()->create([
            'token_hash' => DocumentLink::hashToken($token),
            'disk' => $file->disk,
            'path' => $file->path,
            'filename' => $file->filename,
            'mime' => $file->mime,
            'size' => $file->size,
            'submission_uuid' => $file->submissionUuid,
            'rendition_id' => $file->renditionId,
            'expires_at' => $expiresAt,
        ]);

        return new DeliveredLink($link->id, $file->filename, route('documents.link', ['token' => $token]), $expiresAt);
    }
}
