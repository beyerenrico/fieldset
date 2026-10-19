<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Chain\AuditChain;
use App\Domain\Audit\Chain\ChainHeads;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Instance\Backup\AgeCli;
use App\Domain\Instance\Backup\BackupChecksums;
use App\Domain\Instance\Backup\BackupConfig;
use App\Domain\Instance\Backup\BackupEntry;
use App\Domain\Instance\Backup\BackupFailed;
use App\Domain\Instance\Backup\BackupName;
use App\Domain\Instance\Backup\BackupRun;
use App\Domain\Instance\Backup\BackupRunLog;
use App\Domain\Instance\Backup\BackupRunner;
use App\Domain\Instance\Backup\BackupRunRecorder;
use App\Domain\Instance\Backup\BackupSealer;
use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Backup\BackupToolResult;
use App\Domain\Instance\Backup\BackupTools;
use App\Domain\Instance\Backup\BackupWriter;
use App\Domain\Instance\Backup\PortableSettings;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Submissions\Models\Attachment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\Instance\BackupScratchDatabase;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Writing a backup (R38, R67, KTD16, plan U16, ADR 0084) against a real Postgres 16 and the host's pg_dump/pg_restore 16:
 * one exported snapshot for the dump, the audit heads and the file references; then storage; checksums and the HMAC
 * last; a failure leaves nothing. The data lives in a committed scratch database (BackupScratchDatabase): the snapshot
 * and pg_dump run in their own sessions.
 */

const WRITER_KEY = '0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    $version = new Process(['pg_dump', '--version']);
    $version->run();
    if (preg_match('/ (\d+)\./', $version->getOutput(), $major) !== 1 || (int) $major[1] < 16) {
        // CI installs postgresql-client (16) and the CI image PGDG's client 16; without one these tests cannot run.
        $this->markTestSkipped('pg_dump 16 or newer is not installed (found: '.(trim($version->getOutput()) ?: 'none').'); the backup writer needs it.');
    }
    BackupScratchDatabase::create();
    $this->directory = TemporaryInstance::directory();
    $this->storage = $this->directory->root.'/storage';
    mkdir($this->storage.'/private/attachments', 0700, true);
    $state = InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $this->directory->root, 501, 20, 501)->withRelease('2.0.0-test', [])->withUrl('https://forms.example.com')->withMode('domain')
        ->withSettings(['domain' => 'forms.example.com', 'smtp_host' => 'smtp.example.com', 'mail' => 'smtp', 'backup_retention' => 3, 'port' => 8081]);
    $this->directory->ensureDirectory('backups');
    $this->directory->write(BackupStore::META, PortableSettings::document($state));
    $this->project = $state->project();
});

afterEach(function (): void {
    BackupScratchDatabase::drop();
    TemporaryInstance::cleanup();
});

function writerConfig(object $test, array $overrides = []): BackupConfig
{
    $db = BackupScratchDatabase::libpq(BackupScratchDatabase::name());

    return new BackupConfig(...[
        'instanceRoot' => $test->directory->root, 'storagePath' => $test->storage, 'ownerUid' => posix_getuid(), 'ownerGid' => posix_getgid(),
        'schedule' => '03:30', 'timeZone' => 'UTC', 'retention' => 3, 'minFreeMib' => 0, 'recipients' => [], 'hmacKey' => WRITER_KEY,
        'fingerprints' => ['0123-4567-89ab-cdef'], 'appVersion' => '2.0.0-test',
        'database' => ['host' => $db['PGHOST'], 'port' => $db['PGPORT'], 'database' => $db['PGDATABASE'], 'username' => $db['PGUSER'], 'password' => $db['PGPASSWORD']],
        ...$overrides,
    ]);
}

function writer(object $test, ?BackupConfig $config = null, ?BackupTools $tools = null, ?AgeCli $age = null, ?Closure $afterSnapshot = null): BackupWriter
{
    return new BackupWriter(new BackupStore($test->directory), DB::connection(BackupScratchDatabase::CONNECTION), $tools ?? new BackupTools, $age ?? new AgeCli, $config ?? writerConfig($test), $afterSnapshot);
}

/** An organization with audit entries and an attachment whose file is in storage, plus one whose file is not. */
function writerData(object $test): void
{
    $org = makeOrganization();
    app(TenantContext::class)->run($org, function () use ($test): void {
        app(AuditLogger::class)->record('x.backup_test');
        Attachment::factory()->create(['path' => 'attachments/present.jpg']);
        Attachment::factory()->create(['path' => 'attachments/missing.jpg']);
        file_put_contents($test->storage.'/private/attachments/present.jpg', 'jpeg bytes');
    });
    app(TenantContext::class)->clear();
}

/** @return list<string> */
function tarMembers(string $file): array
{
    $process = new Process(['tar', '-tzf', $file]);
    $process->mustRun();

    return array_values(array_filter(array_map(static fn (string $line): string => preg_replace('#^\./#', '', $line) ?? $line, explode("\n", trim($process->getOutput())))));
}

it('writes every artifact, verifies the dump, and authenticates the set with SHA256SUMS and its HMAC', function (): void {
    writerData($this);

    $result = writer($this)->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::parse('2026-10-19T12:00:00Z')));
    $path = $result->path;
    $manifest = json_decode((string) file_get_contents($path.'/manifest.json'), true);
    $settings = json_decode((string) file_get_contents($path.'/settings.json'), true);
    $listed = new Process(['pg_restore', '--list', $path.'/database.dump']);
    $listed->mustRun();

    expect($result->name->name())->toBe('manual-20261019T120000Z')
        ->and(array_values(array_diff(scandir($path) ?: [], ['.', '..'])))->toBe(['SHA256SUMS', 'SHA256SUMS.hmac', 'audit-heads.json', 'database.dump', 'manifest.json', 'settings.json', 'storage.tar.gz'])
        ->and(BackupChecksums::verify($path, WRITER_KEY))->toBe([])
        ->and(BackupChecksums::verify($path, str_repeat('x', 32)))->toBe(['the HMAC does not match (another APP_KEY, or the sums were changed)'])
        ->and($listed->getOutput())->toContain('TABLE DATA public activity_log')->toContain('TABLE DATA public backup_runs')
        ->and($manifest)->toMatchArray(['format' => 'fsa-backup', 'kind' => 'manual', 'encrypted' => false, 'includes_secrets' => false, 'app_key_fingerprints' => ['0123-4567-89ab-cdef']])
        ->and($manifest['instance'])->toBe(['uuid' => '3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', 'project' => $this->project, 'version' => '2.0.0-test', 'mode' => 'domain'])
        ->and($manifest['versions']['pg_dump'])->toStartWith('pg_dump (PostgreSQL) 16.')
        ->and($manifest['versions']['postgres'])->toStartWith('16.')
        ->and($manifest['database']['migrations'])->toBe(DB::connection(BackupScratchDatabase::CONNECTION)->table('migrations')->count())
        ->and($manifest['database']['last_migration'])->toBe('2026_10_26_100200_add_schedule_to_backup_runs')
        ->and($manifest['storage'])->toMatchArray(['referenced_files' => 2, 'missing_files' => 1, 'missing_sample' => ['private/attachments/missing.jpg']])
        ->and(array_keys($manifest['files']))->toBe(['audit-heads.json', 'database.dump', 'settings.json', 'storage.tar.gz'])
        ->and($settings)->toMatchArray(['mode' => 'domain', 'url' => 'https://forms.example.com'])
        ->and($settings['settings'])->toBe(['domain' => 'forms.example.com', 'mail' => 'smtp', 'smtp_host' => 'smtp.example.com', 'backup_retention' => 3])
        ->and($result->missingCount)->toBe(1)
        ->and(array_column($result->warnings, 'code'))->toBe(['files_missing']);
});

it('reads the audit heads in the dump\'s snapshot, so writes during the backup are in neither', function (): void {
    writerData($this);
    $concurrent = function (): void {
        app(TenantContext::class)->clear();
        DB::setDefaultConnection(BackupScratchDatabase::CONCURRENT); // another session, committing while the snapshot is open
        foreach (range(1, 3) as $i) {
            app(AuditLogger::class)->record("x.during_backup_{$i}");
        }
        app(AuditChain::class)->sealPending(); // the chain grows by three sealed entries
        DB::setDefaultConnection(BackupScratchDatabase::CONNECTION);
    };

    $result = writer($this, afterSnapshot: $concurrent)->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::now()));
    $heads = json_decode((string) file_get_contents($result->path.'/audit-heads.json'), true);
    $live = ChainHeads::read(DB::connection(BackupScratchDatabase::CONNECTION));
    $restored = BackupScratchDatabase::restored();
    new Process(['pg_restore', '--no-owner', '--no-privileges', '--dbname='.$restored, $result->path.'/database.dump'], null, BackupScratchDatabase::libpq($restored))->mustRun();
    $inDump = ChainHeads::read(DB::connection(BackupScratchDatabase::RESTORED));

    expect($heads['epoch'])->toBe(1)
        ->and($heads['heads'])->toBe($inDump)->and($inDump)->toHaveCount(2)
        ->and((string) file_get_contents($result->path.'/audit-heads.json'))->toContain('"heads": {') // an object, also for chains 0, 1
        ->and($live[0]['seq'])->toBe($inDump[0]['seq'] + 3)
        ->and(DB::connection(BackupScratchDatabase::RESTORED)->table('activity_log')->count() + 3)->toBe(DB::connection(BackupScratchDatabase::CONNECTION)->table('activity_log')->count());
});

it('archives a 0700 private directory completely and gives every output to the owner, 0700 and 0600', function (): void {
    mkdir($this->storage.'/private/renditions/x', 0700, true);
    file_put_contents($this->storage.'/private/renditions/x/a.pdf', '%PDF-1.7');
    chmod($this->storage.'/private/renditions', 0700);

    $result = writer($this)->write(BackupName::for(BackupName::SCHEDULED, CarbonImmutable::now()));
    $modes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($result->path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $modes[$item->getFilename()] = [substr(sprintf('%o', $item->getPerms()), -3), $item->getOwner()];
    }

    expect(tarMembers($result->path.'/storage.tar.gz'))->toContain('private/renditions/x/a.pdf')->toContain('private/attachments/')
        ->and(substr(sprintf('%o', fileperms($result->path)), -3))->toBe('700')
        ->and(array_unique(array_column($modes, 0)))->toBe(['600'])
        ->and(array_unique(array_column($modes, 1)))->toBe([posix_getuid()])
        ->and(glob($this->directory->root.'/backups/*.partial'))->toBe([]);
});

it('becomes one age file when the instance has recipients, and carries secrets only then', function (): void {
    $age = new AgeCli(base_path('tests/Fixtures/bin/fake-age'), base_path('tests/Fixtures/bin/fake-age-keygen'));
    $recipient = 'age1'.str_repeat('e', 58);

    expect(fn () => writer($this)->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::now()), "APP_KEY=x\n"))->toThrow(BackupFailed::class, 'encrypted backup');

    $result = writer($this, writerConfig($this, ['recipients' => [$recipient]]), age: $age)->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::now()), "APP_KEY=base64:abc\n");
    [$header, $tar] = explode("\n", (string) file_get_contents($result->path.'/backup.tar.age'), 2);
    file_put_contents($this->directory->root.'/set.tar', $tar);
    $members = new Process(['tar', '-tf', $this->directory->root.'/set.tar']);
    $members->mustRun();

    expect(array_values(array_diff(scandir($result->path) ?: [], ['.', '..'])))->toBe(['backup.tar.age'])
        ->and($header)->toBe('fake-age:to:'.$recipient)
        ->and(explode("\n", trim($members->getOutput())))->toBe(['SHA256SUMS', 'SHA256SUMS.hmac', 'audit-heads.json', 'database.dump', 'manifest.json', 'secrets.env', 'settings.json', 'storage.tar.gz'])
        ->and($result->encrypted)->toBeTrue()->and($result->includesSecrets)->toBeTrue();
});

it('writes a safety backup plain even with recipients, so a rollback needs no identity, and seals it afterwards', function (): void {
    $age = new AgeCli(base_path('tests/Fixtures/bin/fake-age'), base_path('tests/Fixtures/bin/fake-age-keygen'));
    $config = writerConfig($this, ['recipients' => ['age1'.str_repeat('e', 58)]]);
    $store = new BackupStore($this->directory);
    $lock = new InstanceLock($this->directory, new RecordingDockerGateway);
    $handle = $lock->acquire(TemporaryInstance::holder('a'), 'upgrade');
    $run = new BackupRun($lock, $store, writer($this, $config, age: $age), new BackupRunRecorder(new BackupRunLog($this->directory, $store), DB::connection(BackupScratchDatabase::CONNECTION)), $config);

    $recorded = $run->run(BackupName::safety('upgrade', CarbonImmutable::now()), TemporaryInstance::holder('b'), $handle->record->lockId);
    $path = $store->path((string) $recorded['backup']);
    $manifest = json_decode((string) file_get_contents($path.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $plain = array_values(array_diff(scandir($path) ?: [], ['.', '..']));
    $verified = BackupChecksums::verify($path, WRITER_KEY);
    $sealed = new BackupSealer($store, $age)->seal((string) $recorded['backup'], $config->recipients);

    expect($recorded)->toMatchArray(['status' => 'succeeded', 'kind' => 'safety-upgrade', 'encrypted' => false])
        ->and($plain)->toContain('database.dump')->not->toContain('backup.tar.age')
        ->and([$manifest['encrypted'], $manifest['recipients']])->toBe([false, []])
        ->and($verified)->toBe([])
        ->and($sealed)->toMatchArray(['encrypted' => true, 'changed' => true])
        ->and(array_values(array_diff(scandir($path) ?: [], ['.', '..'])))->toBe(['backup.tar.age']);
});

it('never puts secrets.env in plaintext into backups/: it stays on the tmpfs, the set only links to it', function (): void {
    $age = new class(base_path('tests/Fixtures/bin/fake-age'), base_path('tests/Fixtures/bin/fake-age-keygen')) extends AgeCli
    {
        /** @var array<string, mixed> */
        public array $seen = [];

        public bool $fail = false;

        public function encryptDirectory(string $directory, array $files, array $recipients, string $output): void
        {
            $plain = [];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($directory), FilesystemIterator::SKIP_DOTS)) as $file) {
                // fake-age does not encrypt: its output (*.age) is left out of the search
                if ($file instanceof SplFileInfo && $file->isFile() && ! $file->isLink() && $file->getExtension() !== 'age' && str_contains((string) file_get_contents($file->getPathname()), 'APP_KEY=base64:secret')) {
                    $plain[] = $file->getPathname();
                }
            }
            $this->seen = ['link' => is_link($directory.'/secrets.env'), 'target' => (string) readlink($directory.'/secrets.env'), 'plain' => $plain, 'listed' => in_array('secrets.env', $files, true)];
            if ($this->fail) {
                throw new RuntimeException('age: killed');
            }
            parent::encryptDirectory($directory, $files, $recipients, $output);
        }
    };
    $config = writerConfig($this, ['recipients' => ['age1'.str_repeat('e', 58)]]);

    $result = writer($this, $config, age: $age)->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::now()), "APP_KEY=base64:secret\n");
    $seen = $age->seen;
    [, $tar] = explode("\n", (string) file_get_contents($result->path.'/backup.tar.age'), 2);
    file_put_contents($this->directory->root.'/set.tar', $tar);
    $secrets = new Process(['tar', '-xOf', $this->directory->root.'/set.tar', 'secrets.env']);
    $secrets->mustRun();
    $age->fail = true;

    expect($seen['link'])->toBeTrue()->and($seen['listed'])->toBeTrue()->and($seen['plain'])->toBe([])
        ->and($seen['target'])->not->toStartWith($this->directory->root)
        ->and(file_exists($seen['target']))->toBeFalse()->and(is_dir(dirname($seen['target'])))->toBeFalse()
        ->and($secrets->getOutput())->toBe("APP_KEY=base64:secret\n") // in the archive as a file, not as the link
        ->and(fn () => writer($this, $config, age: $age)->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::now()), "APP_KEY=base64:secret\n"))->toThrow(BackupFailed::class);
    expect(file_exists($age->seen['target']))->toBeFalse()->and($age->seen['plain'])->toBe([])
        ->and(new BackupStore($this->directory)->partials())->toBe([]);
});

it('estimates a first backup from the database and the storage volume, and gives all programs one deadline', function (): void {
    writerData($this);
    $tools = new class extends BackupTools
    {
        /** @var list<float> */
        public array $timeouts = [];

        protected function run(array $command, array $env = [], float $timeout = self::DEADLINE_SECONDS, ?Closure $stream = null, mixed $stdout = null): BackupToolResult
        {
            $this->timeouts[] = $timeout;

            return parent::run($command, $env, $timeout, $stream, $stdout);
        }
    };
    $writer = writer($this, tools: $tools);
    $database = (int) DB::connection(BackupScratchDatabase::CONNECTION)->scalar('SELECT pg_database_size(current_database())');

    $estimate = $writer->estimateBytes();
    $writer->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::now()));
    $timeouts = array_values(array_filter($tools->timeouts, static fn (float $timeout): bool => $timeout > 60)); // `pg_dump --version` has its own 30 s
    $sorted = $timeouts;
    rsort($sorted);

    expect($estimate)->toBe($database + BackupStore::bytes($this->storage))->and($estimate)->toBeGreaterThan(BackupStore::bytes($this->storage))
        ->and(count($timeouts))->toBe(4) // pg_dump, tar, tar -t, pg_restore --list
        ->and($timeouts)->toBe($sorted) // what the earlier programs used is gone for the later ones
        ->and(max($timeouts))->toBeLessThanOrEqual(BackupTools::DEADLINE_SECONDS)
        ->and(min($timeouts))->toBeGreaterThan(BackupTools::DEADLINE_SECONDS - 300)
        ->and(BackupRunner::TIMEOUT)->toBeGreaterThan(BackupTools::DEADLINE_SECONDS)
        ->and(fn () => BackupTools::remaining(microtime(true) - 1))->toThrow(function (BackupFailed $e): void {
            expect($e->reason)->toBe('timeout');
        });
});

it('removes the partial backup and names the step when a step fails', function (string $step, string $reason): void {
    $tools = new class($step) extends BackupTools
    {
        public function __construct(private readonly string $step) {}

        public function dump(string $snapshot, string $file, array $libpq, float $timeout = self::DEADLINE_SECONDS): BackupToolResult
        {
            return $this->step === 'dump' ? new BackupToolResult(1, '', 'pg_dump: error: connection refused') : parent::dump($snapshot, $file, $libpq, $timeout);
        }

        public function archive(string $source, string $file, float $timeout = self::DEADLINE_SECONDS): BackupToolResult
        {
            return $this->step === 'archive' ? new BackupToolResult(2, '', 'tar: Cannot open: Permission denied') : parent::archive($source, $file, $timeout);
        }

        public function listDump(string $file, float $timeout = self::DEADLINE_SECONDS): BackupToolResult
        {
            return $this->step === 'list' ? new BackupToolResult(1, '', 'pg_restore: error: input file is too short') : parent::listDump($file, $timeout);
        }
    };
    $age = $step === 'encrypt' ? new AgeCli('/nonexistent/age') : new AgeCli;
    $config = writerConfig($this, $step === 'encrypt' ? ['recipients' => ['age1'.str_repeat('f', 58)]] : []);

    expect(fn () => writer($this, $config, $tools, $age)->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::now())))
        ->toThrow(function (BackupFailed $e) use ($reason): void {
            expect($e->reason)->toBe($reason);
        })
        ->and(array_values(array_diff(scandir($this->directory->root.'/backups') ?: [], ['.', '..'])))->toBe([])
        ->and(array_values(array_diff(scandir($this->directory->root.'/'.BackupStore::STAGING) ?: [], ['.', '..', BackupStore::META_FILE])))->toBe([]);
})->with([
    'pg_dump' => ['dump', 'pg_dump_failed'],
    'storage' => ['archive', 'storage_failed'],
    'pg_restore --list' => ['list', 'verify_failed'],
    'encryption' => ['encrypt', 'encrypt_failed'],
]);

it('records a successful scheduled run, prunes old scheduled backups and keeps the others', function (): void {
    $store = new BackupStore($this->directory);
    foreach (['scheduled-20261001T033000Z', 'scheduled-20261002T033000Z', 'scheduled-20261003T033000Z', 'manual-20261001T120000Z', 'safety-upgrade-20261001T130000Z'] as $old) {
        $this->directory->ensureDirectory('backups/'.$old);
        file_put_contents($this->directory->root.'/backups/'.$old.'/database.dump', 'old');
    }
    $this->directory->ensureDirectory('backups/scheduled-20261004T033000Z.partial');
    $config = writerConfig($this, ['retention' => 2]);
    $db = DB::connection(BackupScratchDatabase::CONNECTION);
    $run = new BackupRun(new InstanceLock($this->directory, new RecordingDockerGateway), $store, writer($this, $config), new BackupRunRecorder(new BackupRunLog($this->directory, $store), $db), $config);
    $holder = TemporaryInstance::holder('b');

    $recorded = $run->run(BackupName::for(BackupName::SCHEDULED, CarbonImmutable::now()), $holder);
    $row = $db->table('backup_runs')->sole();

    expect($recorded)->toMatchArray(['status' => 'succeeded', 'kind' => 'scheduled', 'recorded' => true, 'pruned' => ['scheduled-20261001T033000Z', 'scheduled-20261002T033000Z']])
        ->and(array_map(static fn (BackupEntry $entry): string => $entry->name->name(), $store->entries()))->toBe([$recorded['backup'], 'scheduled-20261003T033000Z', 'safety-upgrade-20261001T130000Z', 'manual-20261001T120000Z'])
        ->and(is_dir($this->directory->root.'/backups/scheduled-20261004T033000Z.partial'))->toBeFalse()
        ->and([$row->kind, $row->status, $row->backup, (int) $row->bytes > 0])->toBe(['scheduled', 'succeeded', $recorded['backup'], true])
        ->and(new BackupRunLog($this->directory)->lastSuccess()?->toIso8601ZuluString())->toBe($recorded['started_at'])
        ->and(new InstanceLock($this->directory, new RecordingDockerGateway)->current())->toBeNull();
});

it('prunes through a hidden tombstone and records only backups that are really gone', function (): void {
    $store = new BackupStore($this->directory);
    foreach (['scheduled-20261001T033000Z', 'scheduled-20261002T033000Z'] as $old) {
        $this->directory->ensureDirectory('backups/'.$old.'/locked');
        file_put_contents($this->directory->root.'/backups/'.$old.'/locked/database.dump', 'old');
    }
    chmod($this->directory->root.'/backups/scheduled-20261001T033000Z/locked', 0500); // its file cannot be removed
    $config = writerConfig($this, ['retention' => 1]);
    $run = new BackupRun(new InstanceLock($this->directory, new RecordingDockerGateway), $store, writer($this, $config), new BackupRunRecorder(new BackupRunLog($this->directory, $store), DB::connection(BackupScratchDatabase::CONNECTION)), $config);

    try {
        $recorded = $run->run(BackupName::for(BackupName::SCHEDULED, CarbonImmutable::now()), TemporaryInstance::holder('b'));
        $left = array_values(array_filter(scandir($this->directory->root.'/backups') ?: [], static fn (string $entry): bool => str_starts_with($entry, BackupStore::TOMBSTONE)));
        $listed = array_map(static fn (BackupEntry $entry): string => $entry->name->name(), $store->entries());
    } finally {
        foreach (glob($this->directory->root.'/backups/*/locked') ?: [] as $locked) {
            chmod($locked, 0700);
        }
    }
    $cleaned = $store->cleanPartials();

    expect($recorded['pruned'])->toBe(['scheduled-20261002T033000Z'])
        ->and($listed)->toBe([$recorded['backup']]) // the half-removed one is not listed as a complete backup
        ->and($left)->toHaveCount(1)->and($left[0])->toStartWith(BackupStore::TOMBSTONE.'scheduled-20261001T033000Z-')
        ->and($cleaned)->toBe($left);
});
