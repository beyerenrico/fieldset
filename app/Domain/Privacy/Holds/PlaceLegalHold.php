<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Holds;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Models\LegalHold;
use App\Domain\Privacy\Models\RetentionPolicy;
use App\Domain\Submissions\Models\Submission;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Places a legal hold on a form or a submission (ADR 0039; audited as privacy.legal_hold_placed). A form hold
 * is refused while the form's retention is shorter than the legal-hold minimum: raise it first, so lifting the
 * hold later never releases records into a too-short retention. The form row (or the submission row) is locked
 * for update, so a running retention batch and a concurrent retention change are serialized with the hold.
 */
final readonly class PlaceLegalHold
{
    private const int REASON_MIN = 3;

    private const int REASON_MAX = 1000;

    public function __construct(private AuditLogger $audit) {}

    public function forForm(Form $form, string $reason, User $actor): LegalHold
    {
        return $this->place($form, ['form_id' => $form->id], $reason, $actor, function () use ($form): void {
            DB::table('forms')->where('id', $form->id)->lockForUpdate()->first(['id']);
            $months = RetentionPolicy::query()->where('form_id', $form->id)->value('months');
            if (is_numeric($months) && (int) $months < LegalHolds::minMonths()) {
                throw ValidationException::withMessages(['reason' => __('privacy.holds.retention_too_short', ['months' => LegalHolds::minMonths()])]);
            }
            if (LegalHolds::formHeld($form->id)) {
                throw ValidationException::withMessages(['reason' => __('privacy.holds.already_held')]);
            }
        });
    }

    public function forSubmission(Submission $submission, string $reason, User $actor): LegalHold
    {
        return $this->place($submission, ['submission_uuid' => $submission->uuid], $reason, $actor, function () use ($submission): void {
            Submission::query()->whereKey($submission->uuid)->lockForUpdate()->first(['uuid']);
            if (LegalHolds::submissionHeld($submission->uuid)) {
                throw ValidationException::withMessages(['reason' => __('privacy.holds.already_held')]);
            }
        });
    }

    /**
     * @param  array<string, int|string>  $target
     * @param  Closure(): void  $lockAndCheck
     */
    private function place(Model $subject, array $target, string $reason, User $actor, Closure $lockAndCheck): LegalHold
    {
        Gate::forUser($actor)->authorize('create', LegalHold::class);
        $reason = trim($reason);
        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            throw ValidationException::withMessages(['reason' => __('privacy.holds.reason_invalid', ['min' => self::REASON_MIN, 'max' => self::REASON_MAX])]);
        }

        try {
            return DB::transaction(function () use ($subject, $target, $reason, $actor, $lockAndCheck): LegalHold {
                $lockAndCheck();
                $hold = LegalHold::query()->create([...$target, 'reason' => $reason, 'placed_by' => $actor->id]);
                $this->audit->record('privacy.legal_hold_placed', $subject, ['hold_id' => $hold->id, 'reason' => $reason], $actor);

                return $hold;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reason' => __('privacy.holds.already_held')]);
        }
    }
}
