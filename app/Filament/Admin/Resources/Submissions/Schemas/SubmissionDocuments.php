<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Schemas;

use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Renditions\RenditionLinks;
use App\Domain\Identity\Models\User;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * View model of the inbox "Documents" section (M3-T04/T08): every rendition (newest first) with the
 * versions it used, its size and a short input hash, a 5-minute signed download link (the download
 * re-checks the viewer and is audited), and the "send to customer" requests with their mail status.
 */
final class SubmissionDocuments
{
    public const int HASH_PREFIX = 12;

    /** @return array{renditions: list<array<string, mixed>>, sends: list<array<string, mixed>>} */
    public static function viewData(Submission $submission, ?User $viewer, string $timezone): array
    {
        $renditions = Rendition::query()->where('submission_uuid', $submission->uuid)
            ->with(['formVersion:id,version', 'designVersion:id,design_id,version', 'designVersion.design:id,name'])
            ->orderByDesc('id')->get();
        $sends = DocumentSend::query()->where('submission_uuid', $submission->uuid)
            ->with(['requester:id,name', 'mailMessage:id,status'])->orderByDesc('id')->get();

        return [
            'renditions' => array_values($renditions->map(fn (Rendition $r): array => self::rendition($r->setRelation('submission', $submission), $viewer, $timezone))->all()),
            'sends' => array_values($sends->map(fn (DocumentSend $s): array => self::send($s, $timezone))->all()),
        ];
    }

    /** @return array<string, mixed> */
    private static function rendition(Rendition $rendition, ?User $viewer, string $timezone): array
    {
        return [
            'uuid' => $rendition->uuid,
            'created_at' => self::when($rendition->created_at, $timezone),
            'reason' => __('inbox.documents.reason.'.$rendition->reason),
            'form_version' => $rendition->formVersion->version,
            'design' => $rendition->designVersion->design->name,
            'design_version' => $rendition->designVersion->version,
            'renderer_version' => $rendition->renderer_version,
            'pages' => $rendition->page_count,
            'size' => Number::fileSize($rendition->size, 1),
            'hash' => substr($rendition->input_hash, 0, self::HASH_PREFIX),
            'input_hash' => $rendition->input_hash,
            'url' => $viewer instanceof User && $viewer->can('view', $rendition) ? RenditionLinks::temporaryUrl($rendition, $viewer) : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function send(DocumentSend $send, string $timezone): array
    {
        return [
            'recipient' => $send->recipient_name === null ? $send->recipient_email : "{$send->recipient_name} <{$send->recipient_email}>",
            'status' => $send->status->label(),
            'status_color' => $send->status->color(),
            'mail_status' => $send->mailMessage === null ? null : __('mail.status.'.$send->mailMessage->status->value),
            'requested_by' => $send->requester === null ? __('inbox.detail.system') : $send->requester->name,
            'requested_at' => self::when($send->created_at, $timezone),
            'error' => $send->error,
        ];
    }

    private static function when(Carbon $at, string $timezone): string
    {
        return $at->copy()->setTimezone($timezone)->settings(['locale' => app()->getLocale()])->isoFormat('L LT');
    }
}
