<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Retention;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Models\Form;
use App\Domain\Privacy\Enums\RetentionAction;
use App\Domain\Privacy\Holds\LegalHolds;
use App\Domain\Privacy\Models\RetentionPolicy;
use App\Domain\Submissions\Models\Submission;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Applies every retention policy of the current organization (ADR 0039). A submission is due when its submit
 * time (drafts: creation time) is older than the policy's months. Forms under legal hold and submissions with
 * their own hold are skipped. Each due submission is decided again under its row lock (RetainSubmission): due
 * drafts are deleted (they were never submitted); others are deleted or anonymized as the policy says.
 * Idempotent: anonymized submissions are marked, deleted ones are gone. At most `privacy.retention.batch`
 * submissions per form and run, and no new work after $until (at least one submission per run); `more` asks
 * the caller to run again. One summary audit entry `privacy.retention_applied` per form that changed, besides
 * one entry per submission.
 */
final readonly class ApplyRetention
{
    public const string REASON = 'retention';

    public function __construct(private RetainSubmission $retain, private AuditLogger $audit) {}

    public static function batch(): int
    {
        return max(1, (int) config('privacy.retention.batch', 500));
    }

    public static function cutoff(int $months): CarbonInterface
    {
        return now()->subMonthsNoOverflow($months);
    }

    /**
     * Submissions of a form that a period and action make due now, without those under their own hold (form
     * holds are checked per form). Also counts what shortening a retention period would remove.
     *
     * @return Builder<Submission>
     */
    public static function dueQuery(int $formId, int $months, RetentionAction $action): Builder
    {
        $query = Submission::query()->where('form_id', $formId)
            ->whereRaw('COALESCE(submitted_at, created_at) < ?', [self::cutoff($months)->toDateTimeString()])
            ->when($action === RetentionAction::Anonymize, fn (Builder $q) => $q->whereNull('anonymized_at'));

        return LegalHolds::withoutHeldSubmissions($query);
    }

    public function handle(?CarbonInterface $until = null): RetentionRun
    {
        $run = new RetentionRun;
        $attempted = 0;
        $policies = RetentionPolicy::query()->with('form')->whereNotNull('months')->orderBy('id')->get();
        foreach ($policies as $policy) {
            if ($attempted > 0 && $this->timeIsUp($until)) {
                return $run->plus(0, 0, 0, true);
            }
            if (LegalHolds::formHeld($policy->form_id)) {
                $run = $run->plus(0, 0, 1, false);

                continue;
            }
            [$anonymized, $deleted, $more, $tried] = $this->apply($policy, $until);
            $attempted += $tried;
            $run = $run->plus($anonymized, $deleted, 0, $more);
        }

        return $run;
    }

    /** @return array{0: int, 1: int, 2: bool, 3: int} anonymized, deleted, more, attempted */
    private function apply(RetentionPolicy $policy, ?CarbonInterface $until): array
    {
        $cutoff = self::cutoff((int) $policy->months);
        $due = $this->due($policy);
        $anonymized = 0;
        $deleted = 0;
        $attempted = 0;
        $timedOut = false;
        foreach ($due as $submission) {
            $attempted++;
            try {
                $done = $this->retain->handle($submission->uuid);
                $anonymized += $done === RetentionAction::Anonymize ? 1 : 0;
                $deleted += $done === RetentionAction::Delete ? 1 : 0;
            } catch (Throwable $e) {
                report($e); // one broken submission must not stop the organization's retention
                $this->remember($policy, $submission->uuid);
            }
            if ($this->timeIsUp($until)) {
                $timedOut = $attempted < $due->count();
                break;
            }
        }

        $this->summarize($policy, $cutoff, $anonymized, $deleted);

        return [$anonymized, $deleted, $timedOut || ($anonymized + $deleted > 0 && $due->count() >= self::batch()), $attempted];
    }

    private function summarize(RetentionPolicy $policy, CarbonInterface $cutoff, int $anonymized, int $deleted): void
    {
        $policy->forceFill(['last_applied_at' => now()])->save();
        if ($anonymized + $deleted > 0 && $policy->form instanceof Form) {
            $this->audit->system('privacy.retention_applied', $policy->form, [
                'action' => $policy->action->value, 'months' => $policy->months, 'cutoff' => $cutoff->toIso8601String(),
                'anonymized' => $anonymized, 'deleted' => $deleted,
            ]);
        }
    }

    private function timeIsUp(?CarbonInterface $until): bool
    {
        return $until instanceof CarbonInterface && now()->greaterThanOrEqualTo($until);
    }

    /** @return Collection<int, Submission> */
    private function due(RetentionPolicy $policy): Collection
    {
        $query = self::dueQuery($policy->form_id, (int) $policy->months, $policy->action);
        $failed = $this->failed($policy);
        if ($failed !== []) {
            $query->whereNotIn('uuid', $failed);
        }

        return $query->orderBy('created_at')->limit(self::batch())->get(['uuid']);
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
