<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Retention;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Models\Form;
use App\Domain\Privacy\Enums\RetentionAction;
use App\Domain\Privacy\Holds\LegalHolds;
use App\Domain\Privacy\Models\RetentionPolicy;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Applies every retention policy of the current organization (ADR 0039). A submission is due when its submit
 * time (drafts: creation time) is older than the policy's months. Forms under legal hold and submissions with
 * their own hold are skipped. Due drafts are deleted (they were never submitted); others are deleted or
 * anonymized as the policy says. Idempotent: anonymized submissions are marked, deleted ones are gone. At most
 * `privacy.retention.batch` submissions per form and run; `more` asks the caller to run again. One summary
 * audit entry `privacy.retention_applied` per form that changed, besides one entry per submission.
 */
final readonly class ApplyRetention
{
    public const string REASON = 'retention';

    public function __construct(
        private AnonymizeSubmission $anonymize,
        private DeleteSubmissionRecord $delete,
        private AuditLogger $audit,
    ) {}

    public static function batch(): int
    {
        return max(1, (int) config('privacy.retention.batch', 500));
    }

    public function handle(): RetentionRun
    {
        $run = new RetentionRun;
        $policies = RetentionPolicy::query()->with('form')->whereNotNull('months')->orderBy('id')->get();
        foreach ($policies as $policy) {
            if (LegalHolds::formHeld($policy->form_id)) {
                $run = $run->plus(0, 0, 1, false);

                continue;
            }
            [$anonymized, $deleted, $more] = $this->apply($policy);
            $run = $run->plus($anonymized, $deleted, 0, $more);
        }

        return $run;
    }

    /** @return array{0: int, 1: int, 2: bool} */
    private function apply(RetentionPolicy $policy): array
    {
        $cutoff = now()->subMonthsNoOverflow((int) $policy->months);
        $due = $this->due($policy, $cutoff->toDateTimeString());
        $anonymized = 0;
        $deleted = 0;
        foreach ($due as $submission) {
            try {
                if ($submission->status === SubmissionStatus::Draft || $policy->action === RetentionAction::Delete) {
                    $deleted += $this->delete->handle($submission, self::REASON) ? 1 : 0;
                } else {
                    $anonymized += $this->anonymize->handle($submission, self::REASON) ? 1 : 0;
                }
            } catch (Throwable $e) {
                report($e); // one broken submission must not stop the organization's retention
                $this->remember($policy, $submission->uuid);
            }
        }

        $policy->forceFill(['last_applied_at' => now()])->save();
        if ($anonymized + $deleted > 0 && $policy->form instanceof Form) {
            $this->audit->system('privacy.retention_applied', $policy->form, [
                'action' => $policy->action->value, 'months' => $policy->months, 'cutoff' => $cutoff->toIso8601String(),
                'anonymized' => $anonymized, 'deleted' => $deleted,
            ]);
        }

        return [$anonymized, $deleted, $anonymized + $deleted > 0 && $due->count() >= self::batch()];
    }

    /** @return Collection<int, Submission> */
    private function due(RetentionPolicy $policy, string $cutoff): Collection
    {
        $query = Submission::query()->where('form_id', $policy->form_id)
            ->whereRaw('COALESCE(submitted_at, created_at) < ?', [$cutoff])
            ->when($policy->action === RetentionAction::Anonymize, fn ($q) => $q->whereNull('anonymized_at'));

        $failed = $this->failed($policy);
        if ($failed !== []) {
            $query->whereNotIn('uuid', $failed);
        }

        return LegalHolds::withoutHeldSubmissions($query)->orderBy('created_at')->limit(self::batch())->get(['uuid', 'status', 'form_id']);
    }

    /**
     * Submissions whose processing failed are skipped for a day, so they never starve the rest of the form.
     *
     * @return list<string>
     */
    private function failed(RetentionPolicy $policy): array
    {
        $failed = Cache::get($this->failedKey($policy), []);

        return is_array($failed) ? array_values(array_filter($failed, is_string(...))) : [];
    }

    private function remember(RetentionPolicy $policy, string $uuid): void
    {
        $failed = array_slice(array_values(array_unique([...$this->failed($policy), $uuid])), -1000);
        Cache::put($this->failedKey($policy), $failed, now()->addDay());
    }

    private function failedKey(RetentionPolicy $policy): string
    {
        return 'privacy:retention-failed:'.$policy->id;
    }
}
