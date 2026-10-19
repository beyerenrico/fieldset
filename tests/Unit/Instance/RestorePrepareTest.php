<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\AgeCli;
use App\Domain\Instance\Backup\BackupTools;
use App\Domain\Instance\Backup\KeyFingerprint;
use App\Domain\Instance\Console\RestorePrepareCommand;
use App\Domain\Instance\Restore\MigrationSet;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Instance\BackupSetFixture;
use Tests\Support\Instance\TarBuilder;

/*
 * `fsa restore-prepare` (plan U17, R41, R67, KTD16, KTD18, ADR 0085), the step that runs without socket and network:
 * decrypt with identity and passphrase, extract safely, check SHA256SUMS and the HMAC with keys from outside the
 * archive, judge the storage archive and the dump, compare migration sets. age is tests/Fixtures/bin/fake-age;
 * pg_restore answers from fixtures (the real tools run in J08).
 */

beforeEach(function (): void {
    $this->work = sys_get_temp_dir().'/fsa-test-prepare-'.bin2hex(random_bytes(4));
    mkdir($this->work.'/scratch', 0750, true);
    app()->instance(AgeCli::class, new AgeCli(base_path('tests/Fixtures/bin/fake-age'), base_path('tests/Fixtures/bin/fake-age-keygen')));
    app()->instance(BackupTools::class, BackupSetFixture::tools());
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->work));
});

/** @return array{0: int, 1: array<string, mixed>} */
function prepare(string $source, string $scratch, array $input = [], array $options = []): array
{
    app()->instance(RestorePrepareCommand::STDIN, static fn (): string => json_encode($input, JSON_THROW_ON_ERROR));
    $code = Artisan::call('fsa:restore-prepare', ['--source' => $source, '--scratch' => $scratch, '--json' => true, ...$options]);

    return [$code, json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR)];
}

/** @return list<array{fingerprint: string, hmac_key: string}> */
function instanceKeys(string ...$appKeys): array
{
    return array_map(static fn (string $key): array => ['fingerprint' => KeyFingerprint::of($key), 'hmac_key' => 'base64:'.base64_encode(BackupSetFixture::hmacKey($key))], $appKeys);
}

it('verifies a plain fsa backup in place: checksums, HMAC with the instance key, storage, dump and migrations', function (): void {
    $source = BackupSetFixture::fsa($this->work.'/manual-20261019T120000Z');

    [$code, $json] = prepare($source, $this->work.'/scratch', ['keys' => instanceKeys(BackupSetFixture::OTHER_KEY, BackupSetFixture::APP_KEY)]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['format' => 'fsa', 'encrypted' => false, 'set' => $source, 'name' => 'manual-20261019T120000Z', 'hmac' => 'verified',
            'key_fingerprint' => KeyFingerprint::of(BackupSetFixture::APP_KEY), 'problems' => [], 'secrets' => false])
        ->and($json['data']['storage'])->toMatchArray(['files' => 1, 'directories' => 1, 'archive' => $this->work.'/scratch/storage.tar.gz'])
        ->and(file($this->work.'/scratch/storage-members.txt', FILE_IGNORE_NEW_LINES))->toBe(['private/a.txt'])
        ->and(substr(sprintf('%o', fileperms($this->work.'/scratch/storage.tar.gz')), -3))->toBe('640')
        ->and($json['data']['dump']['rejected'])->toBe([])
        ->and($json['data']['migrations'])->toMatchArray(['relation' => 'same', 'unknown' => [], 'pending' => 0])
        ->and($json['data']['manifest'])->toMatchArray(['version' => '2.0.0-alpha.1', 'app_key_fingerprints' => [KeyFingerprint::of(BackupSetFixture::APP_KEY)]]);
});

it('decrypts an encrypted backup with the identity file and the passphrase into the scratch directory', function (): void {
    $file = BackupSetFixture::encrypt(BackupSetFixture::fsa($this->work.'/manual-20261019T130000Z', extra: ['secrets.env' => "APP_KEY='".BackupSetFixture::APP_KEY."'\n"]));
    $identity = BackupSetFixture::identityFile($this->work.'/identity.age');

    [$code, $json] = prepare($file, $this->work.'/scratch', ['passphrase' => BackupSetFixture::PASSPHRASE, 'keys' => instanceKeys(BackupSetFixture::APP_KEY)], ['--identity-file' => $identity]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['encrypted' => true, 'set' => $this->work.'/scratch/set', 'hmac' => 'verified', 'secrets' => true])
        ->and(scandir($this->work.'/scratch/set'))->toContain('secrets.env', 'database.dump')
        ->and(file_exists($this->work.'/scratch/backup.tar'))->toBeFalse()
        ->and(json_encode($json))->not->toContain(BackupSetFixture::APP_KEY)->not->toContain(BackupSetFixture::PASSPHRASE);
});

it('refuses a wrong passphrase, and an encrypted backup without identity, before writing anything', function (): void {
    $file = BackupSetFixture::encrypt(BackupSetFixture::fsa($this->work.'/manual-20261019T140000Z'));
    $identity = BackupSetFixture::identityFile($this->work.'/identity.age');

    [$wrong, $wrongJson] = prepare($file, $this->work.'/scratch', ['passphrase' => 'not it', 'keys' => []], ['--identity-file' => $identity]);
    [$missing, $missingJson] = prepare($file, $this->work.'/scratch', ['keys' => []]);

    expect($wrong)->toBe(1)->and($wrongJson['errors'][0]['code'])->toBe('decrypt_failed')
        ->and($missing)->toBe(1)->and($missingJson['errors'][0]['code'])->toBe('identity_required')
        ->and(scandir($this->work.'/scratch'))->toBe(['.', '..']);
});

it('reports an HMAC that no instance key verifies, and a modified file', function (): void {
    $source = BackupSetFixture::fsa($this->work.'/manual-20261019T150000Z', appKey: BackupSetFixture::OTHER_KEY);

    [, $unknown] = prepare($source, $this->work.'/scratch', ['keys' => instanceKeys(BackupSetFixture::APP_KEY)]);
    file_put_contents($source.'/settings.json', '{"tampered":true}');
    exec('rm -rf '.escapeshellarg($this->work.'/scratch').' && mkdir '.escapeshellarg($this->work.'/scratch'));
    [, $modified] = prepare($source, $this->work.'/scratch', ['keys' => instanceKeys(BackupSetFixture::OTHER_KEY)]);

    expect($unknown['data'])->toMatchArray(['hmac' => 'mismatch', 'key_fingerprint' => null, 'problems' => []])
        ->and($modified['data'])->toMatchArray(['hmac' => 'unchecked', 'problems' => ['settings.json does not match its checksum']]);
});

it('refuses hostile archives: a ../ storage member, a symlink in the backup directory', function (): void {
    $traversal = BackupSetFixture::fsa($this->work.'/manual-20261019T160000Z', storage: new TarBuilder()->file('./../../etc/cron.d/evil', 'x'));
    [$code, $json] = prepare($traversal, $this->work.'/scratch', ['keys' => instanceKeys(BackupSetFixture::APP_KEY)]);

    $linked = BackupSetFixture::fsa($this->work.'/manual-20261019T170000Z');
    symlink('/etc/passwd', $linked.'/notes.txt');
    exec('rm -rf '.escapeshellarg($this->work.'/scratch').' && mkdir '.escapeshellarg($this->work.'/scratch'));
    [$linkCode, $linkJson] = prepare($linked, $this->work.'/scratch', ['keys' => instanceKeys(BackupSetFixture::APP_KEY)]);

    expect($code)->toBe(1)->and($json['errors'][0]['code'])->toBe('backup_unsafe')->and($json['errors'][0]['message'])->toContain('leaves its root')
        ->and(file_exists('/etc/cron.d/evil'))->toBeFalse()
        ->and($linkCode)->toBe(1)->and($linkJson['errors'][0]['message'])->toContain('symlink');
});

it('rejects a dump with an extension or an event trigger, and treats an unauthenticated dump strictly', function (): void {
    app()->instance(BackupTools::class, BackupSetFixture::tools(extraEntries: "9001; 3079 1 EXTENSION - dblink \n9002; 3466 1 EVENT TRIGGER - grab postgres\n9003; 1255 1 FUNCTION public steal() postgres\n"));
    $source = BackupSetFixture::fsa($this->work.'/manual-20261019T180000Z');

    [, $authenticated] = prepare($source, $this->work.'/scratch', ['keys' => instanceKeys(BackupSetFixture::APP_KEY)]);
    exec('rm -rf '.escapeshellarg($this->work.'/scratch').' && mkdir '.escapeshellarg($this->work.'/scratch'));
    [, $unauthenticated] = prepare($source, $this->work.'/scratch', ['keys' => []]);

    expect($authenticated['data']['dump']['rejected'])->toHaveCount(2)
        ->and($unauthenticated['data']['hmac'])->toBe('mismatch')
        ->and($unauthenticated['data']['dump']['rejected'])->toHaveCount(3);
});

it('compares migration sets: a newer backup names a migration this image lacks, an older one lacks some', function (): void {
    $image = MigrationSet::image(base_path('database/migrations'));
    app()->instance(BackupTools::class, BackupSetFixture::tools([...$image, '2099_01_01_000000_from_the_future']));
    [, $newer] = prepare(BackupSetFixture::fsa($this->work.'/manual-20261019T190000Z'), $this->work.'/scratch', ['keys' => []]);
    app()->instance(BackupTools::class, BackupSetFixture::tools(array_slice($image, 0, -3)));
    exec('rm -rf '.escapeshellarg($this->work.'/scratch').' && mkdir '.escapeshellarg($this->work.'/scratch'));
    [, $older] = prepare(BackupSetFixture::fsa($this->work.'/manual-20261019T200000Z'), $this->work.'/scratch', ['keys' => []]);

    expect($newer['data']['migrations'])->toMatchArray(['relation' => 'newer', 'unknown' => ['2099_01_01_000000_from_the_future']])
        ->and($older['data']['migrations'])->toMatchArray(['relation' => 'older', 'pending' => 3]);
});

it('reads a 1.0 scripts/backup.sh directory: checksums only, no HMAC, its env noted', function (): void {
    $source = BackupSetFixture::legacy($this->work.'/20261001T020000Z', "APP_KEY=base64:x\n");

    [$code, $json] = prepare($source, $this->work.'/scratch', ['keys' => instanceKeys(BackupSetFixture::APP_KEY)]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['format' => 'legacy', 'hmac' => 'absent', 'problems' => [], 'legacy_env' => true])
        ->and($json['data']['manifest'])->toMatchArray(['kind' => 'legacy', 'created_at' => '20261001T020000Z']);
});

it('says what is not a backup', function (): void {
    mkdir($this->work.'/random');

    [$code, $json] = prepare($this->work.'/random', $this->work.'/scratch');

    expect($code)->toBe(1)->and($json['errors'][0]['code'])->toBe('not_a_backup');
});
