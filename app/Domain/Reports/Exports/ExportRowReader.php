<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

use App\Domain\Submissions\Models\Submission;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Streams the export rows in chunks (keyset on uuid, one `submission_values` query per chunk), so memory
 * stays flat for any number of submissions (BUILD_PROMPT §8.4: 10k in the background).
 */
final class ExportRowReader
{
    /**
     * @param  Builder<Submission>  $query  visible, filtered submissions (ReportScope)
     * @return Generator<int, list<ExportRecord>>
     */
    public function chunks(Builder $query, int $size): Generator
    {
        $base = $query->select(['submissions.uuid', 'submissions.organization_id', 'submissions.number', 'submissions.status',
            'submissions.created_by', 'submissions.submitted_at', 'submissions.form_version_id'])
            ->with(['creator:id,name', 'formVersion:id,version'])->reorder()->orderBy('submissions.uuid')->limit($size);
        $last = null;

        do {
            /** @var Collection<int, Submission> $chunk */
            $chunk = (clone $base)->when($last !== null, fn (Builder $q) => $q->where('submissions.uuid', '>', $last))->get();
            if ($chunk->isEmpty()) {
                return;
            }
            $last = (string) $chunk->last()->uuid;

            yield $this->records($chunk);
        } while ($chunk->count() === $size);
    }

    /**
     * @param  Collection<int, Submission>  $chunk
     * @return list<ExportRecord>
     */
    private function records(Collection $chunk): array
    {
        $values = $rows = $counts = [];
        $projected = DB::table('submission_values')->whereIn('submission_uuid', $chunk->pluck('uuid')->all())
            ->orderBy('id')->get(['submission_uuid', 'key', 'path', 'value_text']);
        foreach ($projected as $row) {
            $uuid = (string) $row->submission_uuid;
            $text = $row->value_text === null ? null : (string) $row->value_text;
            $segments = explode('/', ltrim((string) $row->path, '/'));
            if (count($segments) === 1) {
                $values[$uuid][(string) $row->key] = $text;

                continue;
            }
            [$repeater, $rowId] = $this->rowAddress($segments);
            $rows[$uuid][$repeater][$rowId][(string) $row->key] = $text;
            $counts[$uuid][$segments[0]][$segments[1]] = true;
        }

        return array_values($chunk->map(fn (Submission $s): ExportRecord => new ExportRecord(
            $s, $values[$s->uuid] ?? [], $rows[$s->uuid] ?? [], array_map(count(...), $counts[$s->uuid] ?? []),
        ))->all());
    }

    /**
     * `/items/0/qty` → [items, "1"]; nested `/items/0/parts/2/no` → [items.parts, "1.3"] (1-based rows).
     *
     * @param  list<string>  $segments
     * @return array{0: string, 1: string}
     */
    private function rowAddress(array $segments): array
    {
        array_pop($segments);
        $names = $indexes = [];
        foreach (array_chunk($segments, 2) as $pair) {
            $names[] = $pair[0];
            $indexes[] = is_numeric($pair[1] ?? null) ? (string) ((int) $pair[1] + 1) : '?';
        }

        return [implode('.', $names), implode('.', $indexes)];
    }
}
