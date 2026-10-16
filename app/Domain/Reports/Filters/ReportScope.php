<?php

declare(strict_types=1);

namespace App\Domain\Reports\Filters;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Reports\Schema\ReportField;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\SubmissionVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The submissions a report may use: visible to the user (§8.6 "View submissions": a Reviewer only
 * reports and exports what they may see), of one form, never drafts, matching the filters. Field
 * filters and search are semi-joins on `submission_values` using its (organization, form, key, value)
 * indexes — no ad hoc jsonb parsing of submissions.data (§8.4).
 */
final readonly class ReportScope
{
    public function __construct(private SubmissionVisibility $visibility) {}

    /** @return Builder<Submission> */
    public function submissions(User $user, Form $form, ReportFilters $filters, string $timezone, ?DateRange $range = null, ?FieldCatalog $catalog = null): Builder
    {
        $query = $this->base($user, $form);
        $this->statuses($query, $filters->effectiveStatuses());
        $own = $filters->dateRange($timezone);
        $this->range($query, $own instanceof DateRange ? $own->intersect($range) : $range);
        $this->submitters($query, $filters->submitters);
        $this->groups($query, $form, $filters->groups);
        foreach ($filters->fields as $filter) {
            $this->field($query, $form, $filter, $catalog?->get($filter->key));
        }
        if ($filters->search !== null) {
            $this->search($query, $form, $filters->search);
        }

        return $query;
    }

    /** @return Builder<Submission> visible, non-draft submissions of the form */
    public function base(User $user, Form $form): Builder
    {
        return $this->visibility->apply(Submission::query(), $user)
            ->where('submissions.form_id', $form->id)
            ->where('submissions.status', '!=', SubmissionStatus::Draft->value);
    }

    /**
     * @param  Builder<Submission>  $query
     * @param  list<string>  $statuses
     */
    public function statuses(Builder $query, array $statuses): void
    {
        $query->whereIn('submissions.status', $statuses);
    }

    /** @param  Builder<Submission>  $query */
    public function range(Builder $query, ?DateRange $range): void
    {
        if ($range instanceof DateRange) {
            $query->where('submissions.submitted_at', '>=', $range->from)->where('submissions.submitted_at', '<', $range->until);
        }
    }

    /**
     * @param  Builder<Submission>  $query
     * @param  list<int>  $userIds
     */
    public function submitters(Builder $query, array $userIds): void
    {
        if ($userIds !== []) {
            $query->whereIn('submissions.created_by', $userIds);
        }
    }

    /**
     * Submissions created by members of the groups (§8.6 groups as departments or crews).
     *
     * @param  Builder<Submission>  $query
     * @param  list<int>  $groupIds
     */
    public function groups(Builder $query, Form $form, array $groupIds): void
    {
        if ($groupIds !== []) {
            $query->whereIn('submissions.created_by', DB::table('group_user')->select('user_id')
                ->where('organization_id', $form->organization_id)->whereIn('group_id', $groupIds));
        }
    }

    /** @param  Builder<Submission>  $query */
    public function field(Builder $query, Form $form, FieldFilter $filter, ?ReportField $field): void
    {
        $values = $this->values($form)->where('key', $filter->key);

        match ($filter->operator) {
            FieldFilter::IN => $query->whereIn('submissions.uuid', $this->in($values, $filter, $field)),
            FieldFilter::IS => $filter->values[0] === true
                ? $query->whereIn('submissions.uuid', $values->where('value_text', 'true'))
                : $query->whereNotIn('submissions.uuid', $values->where('value_text', 'true')),
            default => $query->whereIn('submissions.uuid', $this->between($values, $filter, $field)),
        };
    }

    /**
     * Number, any value or the submitter's name contains the term (case-insensitive).
     *
     * @param  Builder<Submission>  $query
     */
    public function search(Builder $query, Form $form, string $term): void
    {
        $like = '%'.addcslashes($term, '%_\\').'%';
        $query->where(fn (Builder $q) => $q->where('submissions.number', 'ilike', $like)
            ->orWhereIn('submissions.uuid', $this->values($form)->where('value_text', 'ilike', $like))
            ->orWhereIn('submissions.created_by', DB::table('users')->select('id')->where('name', 'ilike', $like)));
    }

    private function values(Form $form): QueryBuilder
    {
        return DB::table('submission_values')->select('submission_uuid')
            ->where('organization_id', $form->organization_id)->where('form_id', $form->id);
    }

    private function in(QueryBuilder $values, FieldFilter $filter, ?ReportField $field): QueryBuilder
    {
        if ($field?->type !== 'multiselect') {
            return $values->whereIn('value_text', $filter->values);
        }

        $literal = '{'.implode(',', array_map(static fn (mixed $v): string => '"'.addcslashes((string) $v, '"\\').'"', $filter->values)).'}';

        // CASE keeps the jsonb cast away from rows that are not JSON arrays (evaluation order is guaranteed).
        return $values->whereRaw("case when left(value_text, 1) = '[' then jsonb_exists_any(value_text::jsonb, ?::text[]) else false end", [$literal]);
    }

    private function between(QueryBuilder $values, FieldFilter $filter, ?ReportField $field): QueryBuilder
    {
        [$from, $until] = [$filter->values[0] ?? null, $filter->values[1] ?? null];
        $probe = is_string($from) ? $from : (string) $until;
        $date = $field?->isDate() ?? FieldFilter::isDay($probe);
        $column = $date ? 'value_date' : 'value_num';
        // A bound of the other type (tampered state, hand-edited JSON) is dropped instead of failing the query.
        $fits = static fn (mixed $v): bool => is_string($v) && ($date ? FieldFilter::isDay($v) : FieldFilter::isDecimal($v));

        return $values->when($fits($from), fn (QueryBuilder $q) => $q->where($column, '>=', $from))
            ->when($fits($until), fn (QueryBuilder $q) => $q->where($column, '<=', $until))
            ->whereNotNull($column);
    }
}
