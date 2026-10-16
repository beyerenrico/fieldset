<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Projection;

use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Projects submitted values into `submission_values` (BUILD_PROMPT §8.4) so reports, filters and
 * aggregates never parse jsonb ad hoc. Repeater values are projected per row with their JSON Pointer
 * path and a dotted key (`items.qty`). Drafts are not projected.
 *
 * The read model reflects the last *submitted* data. While a submission is `changes_requested` its
 * data may hold unsubmitted edits, so projecting it only syncs the status of the existing rows; the
 * values are re-projected on resubmit. Status transitions keep the status column in sync (syncStatus).
 */
final class SubmissionValueProjector
{
    private const array LAYOUT = ['section', 'columns', 'heading', 'paragraph', 'divider', 'image', 'page_break'];

    private const array NUMERIC = ['number', 'money', 'rating'];

    public function project(Submission $submission): void
    {
        if ($submission->status === SubmissionStatus::ChangesRequested) {
            $this->syncStatus($submission);

            return;
        }

        DB::table('submission_values')->where('submission_uuid', $submission->uuid)->delete();
        if ($submission->status === SubmissionStatus::Draft) {
            return;
        }

        $rows = [];
        $definition = $submission->formVersion->definition;
        foreach (is_array($definition->pages ?? null) ? $definition->pages : [] as $page) {
            if ($page instanceof stdClass) {
                $this->scope(is_array($page->elements ?? null) ? $page->elements : [], $submission->data, '', '', $rows);
            }
        }

        $base = [
            'organization_id' => $submission->organization_id, 'form_id' => $submission->form_id,
            'form_version_id' => $submission->form_version_id, 'submission_uuid' => $submission->uuid,
            'status' => $submission->status->value, 'submitted_at' => $submission->submitted_at,
        ];
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('submission_values')->insert(array_map(fn (array $row): array => $base + $row, $chunk));
        }
    }

    /** Updates the status column of the projected rows (status transitions without new data). */
    public function syncStatus(Submission $submission): void
    {
        DB::table('submission_values')->where('submission_uuid', $submission->uuid)
            ->update(['status' => $submission->status->value]);
    }

    /**
     * @param  array<array-key, mixed>  $elements
     * @param  list<array<string, mixed>>  $rows
     */
    private function scope(array $elements, stdClass $data, string $pointer, string $keyPrefix, array &$rows): void
    {
        foreach ($elements as $element) {
            if (! $element instanceof stdClass || ! is_string($element->type ?? null) || ! is_string($element->key ?? null)) {
                continue;
            }
            if (in_array($element->type, self::LAYOUT, true)) {
                $this->scope(is_array($element->elements ?? null) ? $element->elements : [], $data, $pointer, $keyPrefix, $rows);
                foreach (is_array($element->columns ?? null) ? $element->columns : [] as $column) {
                    if ($column instanceof stdClass) {
                        $this->scope(is_array($column->elements ?? null) ? $column->elements : [], $data, $pointer, $keyPrefix, $rows);
                    }
                }

                continue;
            }
            if (! property_exists($data, $element->key) || $data->{$element->key} === null) {
                continue;
            }
            $value = $data->{$element->key};
            $path = $pointer.'/'.$element->key;

            if ($element->type === 'repeater') {
                foreach (is_array($value) ? array_values($value) : [] as $i => $row) {
                    if ($row instanceof stdClass) {
                        $this->scope(is_array($element->elements ?? null) ? $element->elements : [], $row, $path.'/'.$i, $keyPrefix.$element->key.'.', $rows);
                    }
                }

                continue;
            }

            $rows[] = $this->row($element, $value, $path, $keyPrefix.$element->key);
        }
    }

    /** @return array<string, mixed> */
    private function row(stdClass $element, mixed $value, string $path, string $key): array
    {
        $numeric = in_array($element->type, self::NUMERIC, true)
            || ($element->type === 'calculated' && ($element->format ?? 'number') !== 'text');

        return [
            'key' => mb_substr($key, 0, 64),
            'path' => mb_substr($path, 0, 255),
            'role' => is_string($element->role ?? null) ? $element->role : null,
            'value_text' => is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'value_num' => $numeric ? $this->numeric($value) : null,
            'value_date' => in_array($element->type, ['date', 'datetime'], true) && is_string($value) ? substr($value, 0, 10) : null,
        ];
    }

    /** Fits numeric(38,10)? Otherwise only the text column carries the value. */
    private function numeric(mixed $value): ?string
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? $value : '');

        return preg_match('/^-?\d{1,28}(\.\d{1,10})?$/', $text) === 1 ? $text : null;
    }
}
