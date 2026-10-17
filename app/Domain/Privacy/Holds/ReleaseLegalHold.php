<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Holds;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Models\LegalHold;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Releases an active legal hold (ADR 0039; audited as privacy.legal_hold_released). The row stays as history. */
final readonly class ReleaseLegalHold
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(LegalHold $hold, User $actor): LegalHold
    {
        Gate::forUser($actor)->authorize('update', $hold);

        return DB::transaction(function () use ($hold, $actor): LegalHold {
            $hold->forceFill(['released_at' => now(), 'released_by' => $actor->id])->save();
            $subject = $hold->form_id !== null
                ? Form::query()->find($hold->form_id)
                : Submission::query()->find($hold->submission_uuid);
            $this->audit->record('privacy.legal_hold_released', $subject ?? $hold, ['hold_id' => $hold->id, 'reason' => $hold->reason], $actor);

            return $hold;
        });
    }
}
