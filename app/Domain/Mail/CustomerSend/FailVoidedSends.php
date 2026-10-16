<?php

declare(strict_types=1);

namespace App\Domain\Mail\CustomerSend;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Enums\DocumentSendFailure;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Submissions\Enums\SubmissionStatus;
use Illuminate\Database\Query\Builder;

/**
 * Closes "send to customer" requests that still wait for a PDF although their submission was voided
 * (M3 review #5): they would never go out (DeliverCustomerSend refuses them), so they fail with the reason
 * `submission_void` instead of waiting forever. Platform code, run by the scheduled documents:sweep.
 */
final readonly class FailVoidedSends
{
    public function __construct(private TenantContext $tenant) {}

    /** @return int number of failed requests */
    public function handle(): int
    {
        $pending = DocumentSend::query()->withoutTenant()
            ->where('status', DocumentSendStatus::WaitingForDocument->value)
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('submissions')
                ->whereColumn('submissions.uuid', 'document_sends.submission_uuid')
                ->where('submissions.status', SubmissionStatus::Void->value))
            ->distinct()->get(['organization_id', 'submission_uuid']);

        $failed = 0;
        foreach ($pending as $row) {
            $failed += $this->tenant->run($row->organization_id, fn (): int => DocumentSend::failWaiting(
                $row->submission_uuid, DocumentSendFailure::SubmissionVoid,
            ));
        }

        return $failed;
    }
}
