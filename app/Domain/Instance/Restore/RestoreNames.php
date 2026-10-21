<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * The names one restore works with (KTD18, plan U17, ADR 0085), derived from its journal ID so a resumed or abandoned
 * run finds what an interrupted one left: the live database, the scratch database it restores into
 * (`<db>_restore_<id>`), the name the live one is put aside under until the restored one passed its verification
 * (`<db>_pre_restore_<id>`), the restricted role of an unauthenticated archive, and the storage volume now and the fresh
 * one.
 */
final readonly class RestoreNames
{
    public function __construct(
        public string $id,
        public string $live,
        public string $scratch,
        public string $aside,
        public string $role,
        public string $currentVolume,
        public string $freshVolume,
    ) {}

    /** The short ID of a journal, hex only: it becomes part of database, role, volume and directory names. */
    public static function id(string $journalId): string
    {
        return substr(preg_replace('/[^a-f0-9]/', '', strtolower($journalId)) ?? '', 0, 12);
    }

    public static function for(InstanceState $state, string $journalId): self
    {
        $id = self::id($journalId);
        $live = InstanceSettings::of($state)->dbDatabase();
        $base = substr($live, 0, 63 - strlen('_pre_restore_') - strlen($id));

        return new self(
            $id, $live, $base.'_restore_'.$id, $base.'_pre_restore_'.$id, 'fieldset_restore_'.$id,
            RestoreStorage::current($state), RestoreStorage::fresh($state, $id),
        );
    }

    /**
     * The names a journal recorded (phase `plan`): an interrupted run's names, whatever the state says now.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $value = static fn (string $key): string => is_string($data[$key] ?? null) ? $data[$key] : '';

        return new self($value('id'), $value('live'), $value('scratch'), $value('aside'), $value('role'), $value('current_volume'), $value('fresh_volume'));
    }

    /** @return list<string> the strings of a journaled list (fingerprints of keys a swap added) */
    public static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'live' => $this->live, 'scratch' => $this->scratch, 'aside' => $this->aside, 'role' => $this->role,
            'current_volume' => $this->currentVolume, 'fresh_volume' => $this->freshVolume,
        ];
    }
}
