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
 */
final class NumberAllocator
{
    public function allocate(FormVersion $version, DateTimeInterface $at): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Numbers must be allocated inside the submit transaction.');
        }

        [$format, $reset] = self::settings($version->definition);
        $period = NumberFormat::period($reset, $at);

        DB::statement(
            'INSERT INTO number_sequences (organization_id, form_id, period, next_value, created_at, updated_at)
             VALUES (?, ?, ?, 1, now(), now()) ON CONFLICT (organization_id, form_id, period) DO NOTHING',
            [$version->organization_id, $version->form_id, $period],
        );

        $row = DB::selectOne(
            'SELECT id, next_value FROM number_sequences WHERE organization_id = ? AND form_id = ? AND period = ? FOR UPDATE',
            [$version->organization_id, $version->form_id, $period],
        );
        $sequence = (int) $row->next_value;

        DB::update('UPDATE number_sequences SET next_value = next_value + 1, updated_at = now() WHERE id = ?', [$row->id]);

        return NumberFormat::render($format, $at, $sequence);
    }

    /** @return array{0: string, 1: string} format and reset rule */
    private static function settings(stdClass $definition): array
    {
        $numbering = $definition->settings->numbering ?? null;
        if (! $numbering instanceof stdClass || ! is_string($numbering->format ?? null)) {
            return [NumberFormat::DEFAULT_FORMAT, 'never'];
        }

        return [$numbering->format, is_string($numbering->reset ?? null) ? $numbering->reset : 'never'];
    }
}
