<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

use App\Domain\Forms\Models\FormVersion;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

/**
 * Gap-free numbers per form and period (BUILD_PROMPT §9.2): `INSERT … ON CONFLICT DO NOTHING` on the
 * period row, then `SELECT … FOR UPDATE`, inside the caller's transaction (the status change). A rolled
 * back submit therefore returns its number.
 *
 * Versions of one form may use different formats and reset rules, so two period rows can render the
 * same string (e.g. after `reset: never` → `yearly`). The allocator holds a per-form advisory lock and
 * skips values whose number already exists; a skipped value is an existing number, so the issued
 * series still has no holes. A changed format continues the period's counter.
 */
final class NumberAllocator
{
    private const int CANDIDATES_PER_QUERY = 50;

    public function allocate(FormVersion $version, DateTimeInterface $at): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Numbers must be allocated inside the submit transaction.');
        }

        [$format, $reset] = $this->settings($version->definition);
        $period = NumberFormat::period($reset, $at);
        $orgId = $version->organization_id;
        $formId = $version->form_id;

        // Serializes numbering per form across all period rows (the unique index is per form).
        DB::select('SELECT pg_advisory_xact_lock(?)', [(int) hexdec(substr(hash('sha256', 'number_sequences|'.$formId), 0, 15))]);

        DB::statement(
            'INSERT INTO number_sequences (organization_id, form_id, period, next_value, created_at, updated_at)
             VALUES (?, ?, ?, 1, now(), now()) ON CONFLICT (organization_id, form_id, period) DO NOTHING',
            [$orgId, $formId, $period],
        );

        $row = DB::selectOne(
            'SELECT id, next_value FROM number_sequences WHERE organization_id = ? AND form_id = ? AND period = ? FOR UPDATE',
            [$orgId, $formId, $period],
        );
        [$sequence, $number] = $this->firstUnused($orgId, $formId, $format, $at, (int) $row->next_value);

        DB::update('UPDATE number_sequences SET next_value = ?, updated_at = now() WHERE id = ?', [$sequence + 1, $row->id]);

        return $number;
    }

    /** @return array{0: int, 1: string} the first sequence value from `$from` whose number is not taken */
    private function firstUnused(int $orgId, int $formId, string $format, DateTimeInterface $at, int $from): array
    {
        while (true) {
            $candidates = [];
            for ($value = $from; $value < $from + self::CANDIDATES_PER_QUERY; $value++) {
                $candidates[$value] = NumberFormat::render($format, $at, $value);
            }
            $taken = DB::table('submissions')->where('organization_id', $orgId)->where('form_id', $formId)
                ->whereIn('number', array_values($candidates))->pluck('number')->all();

            foreach ($candidates as $value => $number) {
                if (! in_array($number, $taken, true)) {
                    return [$value, $number];
                }
            }
            $from += self::CANDIDATES_PER_QUERY;
        }
    }

    /** @return array{0: string, 1: string} format and reset rule */
    private function settings(stdClass $definition): array
    {
        $numbering = $definition->settings->numbering ?? null;
        if (! $numbering instanceof stdClass || ! is_string($numbering->format ?? null)) {
            return [NumberFormat::DEFAULT_FORMAT, 'never'];
        }

        return [$numbering->format, is_string($numbering->reset ?? null) ? $numbering->reset : 'never'];
    }
}
