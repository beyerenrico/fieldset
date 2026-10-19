<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use App\Domain\Audit\Chain\ChainEpoch;
use App\Domain\Audit\Chain\ChainVerifier;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The checks of `fsa restore --drill` (R41, plan U17, ADR 0085; the 1.0 restore drill's checks) on the scratch database
 * the backup was restored into: row counts of the main tables, the audit chains (epoch, heads, verified), the files the
 * database references against the storage archive's members, one document render (DrillRender), a decrypt sample with
 * the APP keys, and the migration set against this image's. Each check passes, warns or fails; the drill fails only
 * when the data cannot be used: APP keys that open nothing, a render that fails, migrations this image does not know.
 */
final readonly class DrillReport
{
    public const array COUNTED = ['organizations', 'users', 'forms', 'form_versions', 'submissions', 'attachments', 'renditions', 'mail_messages'];

    /** Tables whose rows point at files on the local disk (`private/<path>` in the storage volume, as BackupWriter reads them). */
    public const array FILE_TABLES = ['attachments', 'renditions', 'document_links'];

    public const int MISSING_SAMPLE = 20;

    public function __construct(private DrillRender $render, private ChainVerifier $verifier, private Encrypter $encrypter) {}

    /**
     * @param  list<string>|null  $members  the storage archive's member paths; null without a storage archive
     * @return array<string, mixed>
     */
    public function run(?array $members): array
    {
        $db = DB::connection();
        $report = [
            'database' => (string) $db->getDatabaseName(),
            'counts' => $this->counts($db),
            'audit' => $this->audit($db),
            'files' => $this->files($db, $members),
            'render' => $this->render->run(),
            'decrypt' => new DecryptSample($db, $this->encrypter)->run(),
            'migrations' => $this->migrations($db),
        ];

        return [...$report, 'checks' => $this->checks($report)];
    }

    /** @return array<string, int|null> */
    private function counts(ConnectionInterface $db): array
    {
        $counts = [];
        foreach (self::COUNTED as $table) {
            $counts[$table] = $this->hasTable($db, $table) ? $db->table($table)->count() : null;
        }

        return $counts;
    }

    /** @return array{epoch: int, heads: array<int, array{seq: int, hash: string}>, intact: bool, problems: list<string>} */
    private function audit(ConnectionInterface $db): array
    {
        try {
            $report = $this->verifier->verify();
        } catch (Throwable $e) {
            return ['epoch' => 1, 'heads' => [], 'intact' => false, 'problems' => ['The audit chains could not be verified: '.$e->getMessage()]];
        }
        $epoch = $this->hasTable($db, 'audit_chain_epochs') ? ChainEpoch::current($db) : 1; // a backup from before epochs (1.0)

        return ['epoch' => $epoch, 'heads' => $report->heads, 'intact' => $report->intact(), 'problems' => array_values($report->problems)];
    }

    /**
     * @param  list<string>|null  $members
     * @return array{archive: bool, referenced: int, missing: int, sample: list<string>}
     */
    private function files(ConnectionInterface $db, ?array $members): array
    {
        $present = array_flip(array_map(static fn (string $member): string => (string) preg_replace('#^\./#', '', $member), $members ?? []));
        $referenced = 0;
        $missing = [];
        foreach (self::FILE_TABLES as $table) {
            if (! $this->hasColumn($db, $table, 'disk') || ! $this->hasColumn($db, $table, 'path')) {
                continue;
            }
            foreach ($db->table($table)->select('path')->where('disk', 'local')->whereNotNull('path')->distinct()->orderBy('path')->cursor() as $row) {
                $referenced++;
                $file = 'private/'.$row->path;
                if (! isset($present[$file])) {
                    $missing[] = $file;
                }
            }
        }

        return ['archive' => $members !== null, 'referenced' => $referenced, 'missing' => count($missing), 'sample' => array_slice($missing, 0, self::MISSING_SAMPLE)];
    }

    /** @return array{pending: int, unknown: int} */
    private function migrations(ConnectionInterface $db): array
    {
        $migrator = app('migrator');
        $files = $migrator instanceof Migrator ? array_keys($migrator->getMigrationFiles([database_path('migrations')])) : [];
        $ran = $this->hasTable($db, 'migrations') ? array_map(strval(...), $db->table('migrations')->pluck('migration')->all()) : [];

        return ['pending' => count(array_diff($files, $ran)), 'unknown' => count(array_diff($ran, $files))];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{name: string, status: string, message: string}>
     */
    private function checks(array $report): array
    {
        $organizations = (int) ($report['counts']['organizations'] ?? 0);
        $files = $report['files'];
        $render = $report['render'];
        $failed = $report['decrypt']['failed'];
        $migrations = $report['migrations'];

        return [
            $this->check('counts', $organizations > 0 ? 'pass' : 'warn', "{$organizations} organization(s) restored."),
            $this->check('audit', $report['audit']['intact'] ? 'pass' : 'warn', $report['audit']['intact'] ? 'The audit chains verify.' : implode('; ', $report['audit']['problems'])),
            $this->check('files', ! $files['archive'] || $files['missing'] > 0 ? 'warn' : 'pass', $files['archive'] ? "{$files['missing']} of {$files['referenced']} referenced file(s) missing from storage." : 'The backup has no storage archive; files were not checked.'),
            $this->check('render', match ($render['status']) {
                'rendered' => 'pass', 'skipped' => 'warn', default => 'fail'
            }, $render['message'] ?? "A {$render['pages']}-page PDF of {$render['submission']} rendered."),
            $this->check('decrypt', $failed === [] ? 'pass' : 'fail', $failed === [] ? "{$report['decrypt']['tested']} encrypted column(s) decrypt with the APP keys." : 'The APP keys cannot decrypt '.implode(', ', $failed).'.'),
            $this->check('migrations', $migrations['unknown'] > 0 ? 'fail' : ($migrations['pending'] > 0 ? 'warn' : 'pass'), "{$migrations['pending']} pending, {$migrations['unknown']} unknown to this image."),
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    private function check(string $name, string $status, string $message): array
    {
        return ['name' => $name, 'status' => $status, 'message' => $message];
    }

    private function hasTable(ConnectionInterface $db, string $table): bool
    {
        return $db->selectOne('SELECT to_regclass(?) AS oid', ['public.'.$table])?->oid !== null;
    }

    private function hasColumn(ConnectionInterface $db, string $table, string $column): bool
    {
        return $db->selectOne("SELECT 1 AS found FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?", [$table, $column]) !== null;
    }
}
