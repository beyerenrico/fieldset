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

    private function sequences(): int
    {
        $query = "SELECT sequencename, last_value FROM pg_sequences WHERE schemaname = 'public'";
        $source = collect($this->source->select($query))->mapWithKeys(static fn (stdClass $row): array => [(string) $row->sequencename => $row->last_value]);
        $count = 0;
        foreach ($this->target->select($query) as $row) {
            $floor = $source->get((string) $row->sequencename);
            if ($floor === null || ($row->last_value !== null && (int) $row->last_value >= (int) $floor)) {
                continue;
            }
            $this->target->select('SELECT setval(?::regclass, ?, true)', ['public."'.str_replace('"', '""', (string) $row->sequencename).'"', (int) $floor]);
            $count++;
        }

        return $count;
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
