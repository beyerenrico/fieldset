<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Upgrade;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use stdClass;

/**
 * What the upgrade reads from `pg_stat_activity` and `pg_index` (KTD19 steps 6 and 8, KTD25, plan U18, ADR 0086), from
 * socket-less one-offs of the app service. Only client sessions of the current database count; names and states are
 * reported, never query texts (they may carry personal data).
 *
 * - `foreign()`: the sessions other than this one, which an upgrade's migrations must not run beside;
 * - `activity()`/`terminate()`: the upgrade child's session by its `application_name`, whose activity decides whether
 *   the child is alive and which is terminated when the supervisor removes a hung child;
 * - `invalidIndexes()`: indexes a failed or interrupted build left invalid (`indisvalid = false`).
 */
final readonly class UpgradeSessions
{
    /** application_name of an upgrade child (ChildMigration on the ops side): `fsa_upgrade_<hex>`. */
    public const string APPLICATION = '/^fsa_upgrade_[0-9a-f]{6,64}$/D';

    public function __construct(private Connection $db) {}

    /** @throws InvalidArgumentException */
    public static function application(mixed $value): string
    {
        if (! is_string($value) || preg_match(self::APPLICATION, $value) !== 1) {
            throw new InvalidArgumentException('--application-name must be an upgrade child\'s session name (fsa_upgrade_<hex>).');
        }

        return $value;
    }

    /** @return list<array{pid: int, application_name: string, user: string|null, state: string|null}> */
    public function foreign(): array
    {
        $rows = $this->db->select(
            "SELECT pid, application_name, usename, state FROM pg_stat_activity WHERE datname = current_database() AND backend_type = 'client backend' AND pid <> pg_backend_pid() ORDER BY pid"
        );

        return array_values(array_map(static fn (stdClass $row): array => ['pid' => (int) $row->pid, 'application_name' => (string) $row->application_name, 'user' => is_string($row->usename) ? $row->usename : null, 'state' => is_string($row->state) ? $row->state : null], $rows));
    }

    /** @return list<array{pid: int, state: string|null, wait_event_type: string|null, state_change: string|null}> */
    public function activity(string $application): array
    {
        $rows = $this->db->select('SELECT pid, state, wait_event_type, state_change FROM pg_stat_activity WHERE application_name = ? AND pid <> pg_backend_pid() ORDER BY pid', [$application]);

        return array_values(array_map(static fn (stdClass $row): array => ['pid' => (int) $row->pid, 'state' => is_string($row->state) ? $row->state : null, 'wait_event_type' => is_string($row->wait_event_type) ? $row->wait_event_type : null,
            'state_change' => $row->state_change === null ? null : (string) $row->state_change], $rows));
    }

    /** @return int the sessions terminated */
    public function terminate(string $application): int
    {
        $rows = $this->db->select('SELECT pg_terminate_backend(pid) AS terminated FROM pg_stat_activity WHERE application_name = ? AND pid <> pg_backend_pid()', [$application]);

        return count(array_filter($rows, static fn (stdClass $row): bool => (bool) $row->terminated));
    }

    /** @return list<string> invalid indexes of the public schema */
    public function invalidIndexes(): array
    {
        $rows = $this->db->select("SELECT c.relname FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE NOT i.indisvalid AND n.nspname = 'public' ORDER BY c.relname");

        return array_values(array_map(static fn (stdClass $row): string => (string) $row->relname, $rows));
    }
}
