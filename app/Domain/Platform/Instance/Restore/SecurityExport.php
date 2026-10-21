<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use stdClass;

/**
 * The versioned export of the security state for a manual rollback (R64, plan U18, ADR 0086). `fsa rollback` returns to
 * an older release whose code cannot read the newer schema, so the R64 carry cannot read the pre-rollback database as a
 * second connection the way a restore does (ADR 0085). Instead the current image exports, in a socket-less one-off, the
 * rows the carry reads (FORMAT, VERSION 1): per table its columns and rows, the audit entries after the backup, and the
 * sequences' last values. The previous image materialises the document in a schema of the restored database
 * (SecurityImport) and runs the same carry against it. The document holds password hashes, encrypted MFA secrets and
 * e-mail addresses: fsa keeps it only in a 0600 file of the restore's scratch directory and hands it over on stdin.
 */
final readonly class SecurityExport
{
    public const string FORMAT = 'fieldset-security-state';

    public const int VERSION = 1;

    /** Table => columns the carry reads (`*`: every column; tables only read for their ids keep their ids). */
    public const array TABLES = [
        'users' => '*', 'devices' => '*', 'organization_user' => '*', 'model_has_roles' => '*', 'model_has_permissions' => '*',
        'personal_access_tokens' => ['id', 'tokenable_type', 'tokenable_id', 'organization_id'], 'platform_admins' => '*', 'group_user' => '*',
        'form_access' => '*', 'organizations' => '*', 'webhook_endpoints' => ['id', 'organization_id', 'active', 'deleted_at'],
        'identity_providers' => ['id', 'organization_id', 'status', 'jit_enabled', 'enforce_sso'], 'identity_provider_mappings' => ['id', 'identity_provider_id', 'role_id', 'group_id', 'organization_id'],
        'sso_domains' => ['id', 'organization_id', 'verified_at'], 'external_identities' => ['id', 'identity_provider_id', 'user_id', 'organization_id'],
        'audit_chain_epochs' => '*', 'number_sequences' => '*', 'forms' => ['id', 'organization_id'], 'groups' => ['id', 'organization_id'],
        'roles' => ['id'], 'permissions' => ['id'],
    ];

    /** The audit entries the carry's mode `audit` reads (CarryScope::subjects). */
    public const array ACTIVITY = ['id', 'log_name', 'event', 'subject_type', 'subject_id', 'properties', 'created_at'];

    public function __construct(private Connection $db) {}

    /** @return array{format: string, version: int, generated_at: string, since: string|null, tables: array<string, array{columns: list<string>, rows: list<list<mixed>>}>, sequences: list<array{sequencename: string, last_value: int|null}>} */
    public function export(?CarbonImmutable $since): array
    {
        $tables = [];
        foreach (self::TABLES as $table => $columns) {
            if ($this->db->getSchemaBuilder()->hasTable($table)) {
                $listing = $this->db->getSchemaBuilder()->getColumnListing($table);
                $tables[$table] = $this->rows($table, $columns === '*' ? $listing : array_values(array_intersect($columns, $listing)), null);
            }
        }
        $tables['activity_log'] = $this->rows('activity_log', self::ACTIVITY, $since);
        $sequences = array_values(array_map(static fn (stdClass $row): array => ['sequencename' => (string) $row->sequencename, 'last_value' => $row->last_value === null ? null : (int) $row->last_value],
            $this->db->select("SELECT sequencename, last_value FROM pg_sequences WHERE schemaname = 'public' ORDER BY sequencename")));

        return ['format' => self::FORMAT, 'version' => self::VERSION, 'generated_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(), 'since' => $since?->utc()->toIso8601ZuluString(), 'tables' => $tables, 'sequences' => $sequences];
    }

    /**
     * @param  list<string>  $columns
     * @return array{columns: list<string>, rows: list<list<mixed>>}
     */
    private function rows(string $table, array $columns, ?CarbonImmutable $since): array
    {
        $query = $this->db->table($table)->orderBy($columns[0])->select($columns);
        if ($since instanceof CarbonImmutable) {
            $query->where('created_at', '>=', $since->utc()->format('Y-m-d H:i:s')); // the backup's own second too: times are whole seconds (M12 gate D8)
        }
        $rows = [];
        foreach ($query->cursor() as $row) {
            $values = (array) $row;
            $rows[] = array_map(static fn (string $column): mixed => $values[$column] ?? null, $columns);
        }

        return ['columns' => $columns, 'rows' => $rows];
    }
}
