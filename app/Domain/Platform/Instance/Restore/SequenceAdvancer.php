<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Number sequences never go backwards (plan U17, R64, ADR 0085): after a restore, every Postgres sequence of the restored
 * database (`target`) stands at least where the pre-restore database's (`source`) stood, so row ids handed out after
 * the backup (in URLs, webhooks, exports) are never handed out again; and every submission number counter
 * (`number_sequences`, NumberAllocator) of a form the restored database still has continues after the source's, so the
 * next submission never reuses a number given after the backup.
 */
final readonly class SequenceAdvancer
{
    public function __construct(private ConnectionInterface $source, private ConnectionInterface $target) {}

    /** @return array{sequences: int, number_sequences: int} how many were moved forward (or added) */
    public function advance(): array
    {
        return ['sequences' => $this->sequences(), 'number_sequences' => $this->numbers()];
    }

    /**
     * The last value of every used sequence in `public` (never-used ones have none).
     *
     * @return array<string, int>
     */
    public static function values(ConnectionInterface $db): array
    {
        $rows = $db->select("SELECT sequencename, last_value FROM pg_sequences WHERE schemaname = 'public' AND last_value IS NOT NULL");

        return collect($rows)->mapWithKeys(static fn (stdClass $row): array => [(string) $row->sequencename => (int) $row->last_value])->all();
    }

    /**
     * Moves every sequence of `$target` that stands below its floor up to it (a sequence the floors do not name, or one
     * `$target` lacks, is left alone). `fieldset reset` passes the old database's values (U23 review S1).
     *
     * @param  array<string, int>  $floors  sequence name => last value
     * @return int how many were moved
     */
    public static function raise(ConnectionInterface $target, array $floors): int
    {
        $count = 0;
        foreach ($target->select("SELECT sequencename, last_value FROM pg_sequences WHERE schemaname = 'public'") as $row) {
            $floor = $floors[(string) $row->sequencename] ?? null;
            if ($floor === null || ($row->last_value !== null && (int) $row->last_value >= $floor)) {
                continue;
            }
            $target->select('SELECT setval(?::regclass, ?, true)', ['public."'.str_replace('"', '""', (string) $row->sequencename).'"', $floor]);
            $count++;
        }

        return $count;
    }

    private function sequences(): int
    {
        return self::raise($this->target, self::values($this->source));
    }

    private function numbers(): int
    {
        $forms = array_flip(array_map(strval(...), $this->target->table('forms')->pluck('id')->all()));
        $count = 0;
        foreach ($this->source->table('number_sequences')->get(['organization_id', 'form_id', 'period', 'next_value']) as $row) {
            if (! isset($forms[(string) $row->form_id])) {
                continue; // a form created after the backup is gone with it
            }
            $key = ['organization_id' => $row->organization_id, 'form_id' => $row->form_id, 'period' => $row->period];
            $current = $this->target->table('number_sequences')->where($key)->value('next_value');
            if ($current !== null && (int) $current >= (int) $row->next_value) {
                continue;
            }
            $current === null
                ? $this->target->table('number_sequences')->insert([...$key, 'next_value' => $row->next_value, 'created_at' => now(), 'updated_at' => now()])
                : $this->target->table('number_sequences')->where($key)->update(['next_value' => $row->next_value, 'updated_at' => now()]);
            $count++;
        }

        return $count;
    }
}
