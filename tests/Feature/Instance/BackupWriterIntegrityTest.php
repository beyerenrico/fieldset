<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Instance\Backup\AgeCli;
use App\Domain\Instance\Backup\BackupChecksums;
use App\Domain\Instance\Backup\BackupConfig;
use App\Domain\Instance\Backup\BackupEntry;
use App\Domain\Instance\Backup\BackupFailed;
use App\Domain\Instance\Backup\BackupName;
use App\Domain\Instance\Backup\BackupRun;
use App\Domain\Instance\Backup\BackupRunLog;
use App\Domain\Instance\Backup\BackupRunRecorder;
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
 * The set a backup writes cannot be changed into an authenticated one by whoever else writes into backups/ (M12 gate
 * S1–S3, ADR 0084): SHA256SUMS lists what the writer produced, the set must hold exactly those files in the directory the
 * run created, the plain set of an encrypted backup never lies in backups/, and the service's settings come from
 * staging/backup/, not from backups/.
 */

const INTEGRITY_KEY = '0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    $version = new Process(['pg_dump', '--version']);
    $version->run();
    if (preg_match('/ (\d+)\./', $version->getOutput(), $major) !== 1 || (int) $major[1] < 16) {
        $this->markTestSkipped('pg_dump 16 or newer is not installed; the backup writer needs it.');
    }
    BackupScratchDatabase::create();
    $this->directory = TemporaryInstance::directory();
    $this->storage = $this->directory->root.'/storage';
    mkdir($this->storage.'/private', 0700, true);
    file_put_contents($this->storage.'/private/kept.txt', 'kept');
    $this->state = InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $this->directory->root, 501, 20, 501)->withRelease('2.0.0-test', [])
        ->withUrl('https://forms.example.com')->withMode('domain')->withSettings(['domain' => 'forms.example.com']);
    $this->directory->write(BackupStore::META, PortableSettings::document($this->state));
});

afterEach(function (): void {
    BackupScratchDatabase::drop();
    TemporaryInstance::cleanup();
});

/** @param  list<string>  $recipients */
function integrityConfig(object $test, array $recipients = [], int $retention = 3): BackupConfig
{
    $db = BackupScratchDatabase::libpq(BackupScratchDatabase::name());

    return new BackupConfig(
        $test->directory->root, $test->storage, posix_getuid(), posix_getgid(), '03:30', 'UTC', $retention, 0, $recipients, INTEGRITY_KEY, ['0123-4567-89ab-cdef'],
        ['host' => $db['PGHOST'], 'port' => $db['PGPORT'], 'database' => $db['PGDATABASE'], 'username' => $db['PGUSER'], 'password' => $db['PGPASSWORD']], '2.0.0-test',
    );
}

/** @param  list<string>  $recipients */
function integrityWriter(object $test, ?BackupTools $tools = null, ?AgeCli $age = null, array $recipients = [], ?BackupConfig $config = null): BackupWriter
{
    return new BackupWriter(new BackupStore($test->directory), DB::connection(BackupScratchDatabase::CONNECTION), $tools ?? new BackupTools, $age ?? new AgeCli, $config ?? integrityConfig($test, $recipients));
}

/** BackupTools that let `$tamper` change the partial set once the dump and the archive are written (pg_restore --list runs next). */
function tamperingTools(Closure $tamper): BackupTools
{
    return new class($tamper) extends BackupTools
    {
        public function __construct(private readonly Closure $tamper) {}

        public function listDump(string $file, float $timeout = self::DEADLINE_SECONDS): BackupToolResult
        {
            ($this->tamper)(dirname($file));

            return parent::listDump($file, $timeout);
        }
    };
}

/** A gzip'd tar of one file, as someone would plant it. */
function craftedArchive(string $at): void
{
    $source = dirname($at).'/../crafted-'.bin2hex(random_bytes(3));
    mkdir($source.'/private', 0700, true);
    file_put_contents($source.'/private/planted.php', '<?php // planted');
    new Process(['tar', '-C', $source, '-czf', $at, '.'])->mustRun();
}

function noCompleteBackup(object $test): void
{
    expect(new BackupStore($test->directory)->entries())->toBe([])
        ->and(new BackupStore($test->directory)->partials())->toBe([]);
}

it('fails the run when a file it did not write appears in the set, instead of checksumming it', function (): void {
    $tools = tamperingTools(static function (string $partial): void {
        file_put_contents($partial.'/extra.sql', 'DROP TABLE users;');
    });

    expect(fn () => integrityWriter($this, $tools)->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::now())))
        ->toThrow(function (BackupFailed $e): void {
            expect($e->reason)->toBe('set_changed')->and($e->getMessage())->toContain('"extra.sql"');
        });
    noCompleteBackup($this);
});

it('fails the run when a file is replaced after the writer produced it, so the replacement is never authenticated', function (): void {
    $tools = tamperingTools(static function (string $partial): void {
        unlink($partial.'/'.BackupWriter::STORAGE);
        craftedArchive($partial.'/'.BackupWriter::STORAGE);
    });

    expect(fn () => integrityWriter($this, $tools)->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::now())))
        ->toThrow(function (BackupFailed $e): void {
            expect($e->reason)->toBe('set_changed')->and($e->getMessage())->toContain('storage.tar.gz');
        });
    noCompleteBackup($this);
});

it('fails the run when the partial directory is swapped for a copy', function (): void {
    $tools = tamperingTools(static function (string $partial): void {
        rename($partial, $partial.'-moved');
        mkdir($partial, 0700);
        foreach (array_diff(scandir($partial.'-moved') ?: [], ['.', '..']) as $file) {
            copy($partial.'-moved/'.$file, $partial.'/'.$file);
            chmod($partial.'/'.$file, 0600);
        }
    });

    expect(fn () => integrityWriter($this, $tools)->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::now())))
        ->toThrow(function (BackupFailed $e): void {
            expect($e->reason)->toBe('set_changed')->and($e->getMessage())->toContain('replaced');
        });
    expect(new BackupStore($this->directory)->entries())->toBe([]);
});

it('writes SHA256SUMS from what it produced: the sums match the files and every file is the writer\'s', function (): void {
    $result = integrityWriter($this)->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::now()));
    $manifest = json_decode((string) file_get_contents($result->path.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $migrations = new Process(['pg_restore', '--data-only', '--table=migrations', '--file=-', $result->path.'/database.dump']);
    $migrations->mustRun();

    expect(BackupChecksums::verify($result->path, INTEGRITY_KEY))->toBe([])
        ->and($manifest['files']['database.dump']['sha256'])->toBe(hash_file('sha256', $result->path.'/database.dump'))
        ->and($manifest['files']['storage.tar.gz']['bytes'])->toBe(filesize($result->path.'/storage.tar.gz'))
        ->and($migrations->getOutput())->toContain('COPY public.migrations');
});

it('writes the plain set of an encrypted backup in staging/backup/, never in backups/', function (): void {
    $age = new class(base_path('tests/Fixtures/bin/fake-age'), base_path('tests/Fixtures/bin/fake-age-keygen')) extends AgeCli
    {
        /** @var array{directory?: string, backups?: list<string>} */
        public array $seen = [];

        public function encryptDirectory(string $directory, array $files, array $recipients, string $output): void
        {
            $backups = [];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($output, 2), FilesystemIterator::SKIP_DOTS)) as $file) {
                $backups[] = substr($file->getPathname(), strlen(dirname($output, 2)) + 1);
            }
            $this->seen = ['directory' => $directory, 'backups' => $backups];
            parent::encryptDirectory($directory, $files, $recipients, $output);
        }
    };

    $result = integrityWriter($this, age: $age, recipients: ['age1'.str_repeat('e', 58)])->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::now()));

    expect($age->seen['directory'])->toStartWith($this->directory->root.'/'.BackupStore::STAGING.'/')
        ->and($age->seen['backups'])->toBe([]) // not even the partial set's plain files, while age reads them
        ->and(array_values(array_diff(scandir($result->path) ?: [], ['.', '..'])))->toBe(['backup.tar.age'])
        ->and(array_values(array_diff(scandir($this->directory->root.'/'.BackupStore::STAGING) ?: [], ['.', '..'])))->toBe([BackupStore::META_FILE]);
});

it('takes its settings from staging/backup/, never from a file planted in backups/', function (): void {
    $planted = json_decode(PortableSettings::document($this->state->withRecipients(['age1'.str_repeat('p', 58)])->withSettings(['domain' => 'evil.example'])), true);
    $this->directory->write(BackupStore::LEGACY_META, (string) json_encode($planted));

    $result = integrityWriter($this)->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::now()));
    $settings = json_decode((string) file_get_contents($result->path.'/settings.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($settings['recipients'])->toBe([])
        ->and($settings['settings']['domain'])->toBe('forms.example.com');
});

it('keeps the older scheduled backups that hold files the new ones miss, run after run, until the files are back (M12 gate D5)', function (): void {
    $store = new BackupStore($this->directory);
    foreach (['scheduled-20261001T033000Z', 'scheduled-20261002T033000Z'] as $old) {
        $this->directory->ensureDirectory('backups/'.$old);
        file_put_contents($this->directory->root.'/backups/'.$old.'/database.dump', 'old');
    }
    $log = new BackupRunLog($this->directory, $store);
    $log->append(['kind' => 'scheduled', 'status' => 'succeeded', 'started_at' => '2026-10-02T03:30:00Z', 'backup' => 'scheduled-20261002T033000Z', 'missing_files' => 0]);
    $org = makeOrganization();
    app(TenantContext::class)->run($org, static function (): void {
        Attachment::factory()->create(['path' => 'attachments/gone.jpg']); // its file is not in storage
    });
    app(TenantContext::class)->clear();
    $config = integrityConfig($this, retention: 1);
    $run = new BackupRun(new InstanceLock($this->directory, new RecordingDockerGateway), $store, integrityWriter($this, config: $config), new BackupRunRecorder($log, DB::connection(BackupScratchDatabase::CONNECTION)), $config);

    $held = $run->run(BackupName::for(BackupName::SCHEDULED, CarbonImmutable::now()), TemporaryInstance::holder('b'));
    $kept = array_map(static fn (BackupEntry $entry): string => $entry->name->name(), $store->entries());
    $next = $run->run(BackupName::for(BackupName::SCHEDULED, CarbonImmutable::now()->addMinute()), TemporaryInstance::holder('b'));
    $keptNext = array_map(static fn (BackupEntry $entry): string => $entry->name->name(), $store->entries());
    @mkdir($this->storage.'/private/attachments', 0700, true);
    file_put_contents($this->storage.'/private/attachments/gone.jpg', 'jpg'); // the file is back
    $back = $run->run(BackupName::for(BackupName::SCHEDULED, CarbonImmutable::now()->addMinutes(2)), TemporaryInstance::holder('b'));

    // 20261001 has no count in the ledger (unknown), 20261002 missed none: both may hold the file the new ones miss
    $old = ['scheduled-20261001T033000Z', 'scheduled-20261002T033000Z'];
    expect($held)->toMatchArray(['status' => 'succeeded', 'missing_files' => 1, 'pruned' => [], 'retention_held' => ['missing_files' => 1, 'backups' => $old]])
        ->and($kept)->toContain(...$old)
        // the next day misses the same file: the old ones stay held (before this fix the hold lasted one run)
        ->and($next)->toMatchArray(['status' => 'succeeded', 'missing_files' => 1, 'pruned' => [$held['backup']], 'retention_held' => ['missing_files' => 1, 'backups' => $old]])
        ->and($keptNext)->toContain(...$old)
        ->and($back)->toMatchArray(['status' => 'succeeded', 'missing_files' => 0])->not->toHaveKey('retention_held')
        ->and($back['pruned'])->toBe([...$old, $next['backup']]);
});

it('counts the organizations\' branding logos among the files the database references (M12 gate D8)', function (): void {
    $present = makeOrganization();
    $missing = makeOrganization();
    $present->forceFill(['branding' => ['logo_path' => 'branding/'.$present->id.'/logo-0123456789abcdef.png']])->save();
    $missing->forceFill(['branding' => ['logo_path' => 'branding/'.$missing->id.'/logo-fedcba9876543210.png']])->save();
    mkdir($this->storage.'/private/branding/'.$present->id, 0700, true);
    file_put_contents($this->storage.'/private/branding/'.$present->id.'/logo-0123456789abcdef.png', 'png');

    $result = integrityWriter($this)->write(BackupName::for(BackupName::MANUAL, CarbonImmutable::now()));
    $manifest = json_decode((string) file_get_contents($result->path.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($manifest['storage'])->toMatchArray(['referenced_files' => 2, 'missing_files' => 1, 'missing_sample' => ['private/branding/'.$missing->id.'/logo-fedcba9876543210.png']]);
});
