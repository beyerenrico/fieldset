<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Holds;

use App\Domain\Privacy\Models\LegalHold;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Queries about active legal holds (ADR 0039), inside the current tenant. A form hold covers every submission
 * of the form; a submission hold covers that submission. Forms under hold need a retention period of at least
 * `privacy.retention.legal_hold_min_months` (default 120, i.e. ten years; operators check the current
 * statutory periods, e.g. §147 AO / §14b UStG for invoices).
 */
final class LegalHolds
{
    public const int DEFAULT_MIN_MONTHS = 120;

    public static function minMonths(): int
    {
        return max(1, (int) config('privacy.retention.legal_hold_min_months', self::DEFAULT_MIN_MONTHS));
    }

    public static function formHeld(int $formId): bool
    {
        return LegalHold::query()->active()->where('form_id', $formId)->exists();
    }

    public static function submissionHeld(string $uuid): bool
    {
        return LegalHold::query()->active()->where('submission_uuid', $uuid)->exists();
    }

    /**
     * For retention, with the submission row locked: takes a share lock on its form row (PlaceLegalHold and
     * SaveRetentionPolicy lock it for update), so a hold placed while a retention batch runs is always seen.
     */
    public static function heldUnderLock(Submission $submission): bool
    {
        DB::table('forms')->where('id', $submission->form_id)->sharedLock()->first(['id']);

        return self::formHeld($submission->form_id) || self::submissionHeld($submission->uuid);
    }

    /**
     * Excludes submissions with an active hold of their own (form holds are checked per form).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $submissions
     * @return Builder<TModel>
     */
    public static function withoutHeldSubmissions(Builder $submissions): Builder
    {
        return $submissions->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('legal_holds')
            ->whereColumn('legal_holds.submission_uuid', 'submissions.uuid')
            ->whereNull('legal_holds.released_at'));
    }
}
