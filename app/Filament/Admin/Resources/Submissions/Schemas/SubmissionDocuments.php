<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Schemas;

use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Models\RenditionFailure;
use App\Domain\Identity\Models\User;
use App\Domain\Mail\Enums\DocumentSendFailure;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * View model of the inbox "Documents" section (M3-T04/T08): a permanent render failure with a fixed text
 * (ADR 0031), every rendition (newest first) with the versions it used, its size and a short input hash, a
 * download link to the admin route that mints a fresh signed URL on click (the download re-checks the
 * viewer and is audited), and the "send to customer" requests with their mail status and fixed failure texts.
 * An anonymized submission (ADR 0039) keeps its rendition rows as proof but has no PDF files: no download links.
 */
final class SubmissionDocuments
{
    public const int HASH_PREFIX = 12;

    /** @return array{failure: array<string, mixed>|null, anonymized: bool, renditions: list<array<string, mixed>>, sends: list<array<string, mixed>>} */
    public static function viewData(Submission $submission, ?User $viewer, string $timezone): array
    {
        $renditions = Rendition::query()->where('submission_uuid', $submission->uuid)
            ->with(['formVersion:id,version', 'designVersion:id,design_id,version', 'designVersion.design:id,name'])
            ->orderByDesc('id')->get();
        $sends = DocumentSend::query()->where('submission_uuid', $submission->uuid)
            ->with(['requester:id,name', 'mailMessage:id,status'])->orderByDesc('id')->get();
        $failure = RenditionFailure::query()->where('submission_uuid', $submission->uuid)->first();
        $slug = $submission->organization()->value('slug');
        $downloader = $submission->isAnonymized() ? null : $viewer;

        return [
            'failure' => $failure === null ? null : self::failure($failure, $timezone),
            'anonymized' => $submission->isAnonymized(),
            'renditions' => array_values($renditions->map(fn (Rendition $r): array => self::rendition($r->setRelation('submission', $submission), $downloader, $timezone, (string) $slug))->all()),
            'sends' => array_values($sends->map(fn (DocumentSend $s): array => self::send($s, $timezone))->all()),
        ];
    }

    /** @return array<string, mixed> */
    private static function failure(RenditionFailure $failure, string $timezone): array
    {
        return [
            'message' => __('inbox.documents.failure.'.$failure->reason),
            'details' => trans_choice('inbox.documents.failure.details', $failure->attempts, [
                'when' => self::when($failure->failed_at, $timezone), 'count' => $failure->attempts,
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private static function rendition(Rendition $rendition, ?User $viewer, string $timezone, string $organizationSlug): array
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
            'size' => Number::withLocale(app()->getLocale(), fn (): string => (string) Number::fileSize($rendition->size, 1)),
            'hash' => substr($rendition->input_hash, 0, self::HASH_PREFIX),
            'input_hash' => $rendition->input_hash,
            'url' => $viewer instanceof User && $viewer->can('view', $rendition)
                ? route('admin.renditions.download', ['organization' => $organizationSlug, 'rendition' => $rendition->uuid])
                : null,
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
            'requested_by' => $send->requester->name ?? __('inbox.detail.system'),
            'requested_at' => self::when($send->created_at, $timezone),
            'error' => $send->failure_reason?->label()
                ?? ($send->status === DocumentSendStatus::Failed ? DocumentSendFailure::DeliveryFailed->label() : null),
        ];
    }

    private static function when(Carbon $at, string $timezone): string
    {
        return $at->copy()->setTimezone($timezone)->settings(['locale' => app()->getLocale()])->isoFormat('L LT');
    }
}
