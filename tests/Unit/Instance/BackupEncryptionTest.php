<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\AgeCli;
use App\Domain\Instance\Backup\BackupSealer;
use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Backup\KeySetup;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\InvalidInput;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Backup encryption (R40, KTD17, plan U16, ADR 0084): an age key pair, the identity encrypted with the owner's passphrase
 * through batchpass on file descriptor 3 (never a terminal, an environment variable or an argument), written once
 * outside the instance directory and decrypt-tested. age itself is replaced by tests/Fixtures/bin/fake-age, which uses
 * the same arguments and channels; the J08 journey runs the real binary.
 */

const BACKUP_PASSPHRASE = 'correct horse battery staple';

function fakeAge(): AgeCli
{
    return new AgeCli(base_path('tests/Fixtures/bin/fake-age'), base_path('tests/Fixtures/bin/fake-age-keygen'));
}

beforeEach(function (): void {
    $this->outside = sys_get_temp_dir().'/fsa-test-identity-'.bin2hex(random_bytes(4));
    mkdir($this->outside, 0700);
    $this->identity = $this->outside.'/backup-identity.age';
    putenv('FAKE_AGE_FAIL');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->outside));
});

it('writes the passphrase-protected identity once (0600) and decrypt-tests it, the passphrase only on fd 3', function (): void {
    putenv('AGE_PASSPHRASE=leaked'); // the fake refuses to run when it sees this; AgeCli must clear it

    try {
        $recipient = new KeySetup(fakeAge())->create($this->identity, BACKUP_PASSPHRASE);
    } finally {
        putenv('AGE_PASSPHRASE');
    }
    $identity = fakeAge()->decryptWithPassphrase($this->identity, BACKUP_PASSPHRASE);

    expect($recipient)->toMatch('/^age1[0-9a-z]{58}$/')
        ->and(substr(sprintf('%o', fileperms($this->identity)), -3))->toBe('600')
        ->and((string) file_get_contents($this->identity))->toStartWith('fake-age:pass:'.hash('sha256', BACKUP_PASSPHRASE))
        ->and((string) file_get_contents($this->identity))->not->toContain(BACKUP_PASSPHRASE)
        ->and($identity)->toStartWith('AGE-SECRET-KEY-1')
        ->and(fn () => fakeAge()->decryptWithPassphrase($this->identity, 'another passphrase!'))->toThrow(RuntimeException::class, 'incorrect passphrase');
});

it('round-trips a backup set encrypted to the recipient with the identity and its passphrase', function (): void {
    $recipient = new KeySetup(fakeAge())->create($this->identity, BACKUP_PASSPHRASE);
    $set = $this->outside.'/set';
    mkdir($set, 0700);
    file_put_contents($set.'/database.dump', 'PGDMP dump');
    file_put_contents($set.'/manifest.json', '{}');

    fakeAge()->encryptDirectory($set, ['database.dump', 'manifest.json'], [$recipient], $this->outside.'/backup.tar.age');
    $identity = fakeAge()->decryptWithPassphrase($this->identity, BACKUP_PASSPHRASE);
    $tar = fakeAge()->decryptWithIdentity($identity, $this->outside.'/backup.tar.age');
    file_put_contents($this->outside.'/backup.tar', $tar);
    exec('tar -tf '.escapeshellarg($this->outside.'/backup.tar'), $members);

    expect($members)->toBe(['database.dump', 'manifest.json'])
        ->and(fn () => fakeAge()->decryptWithIdentity("AGE-SECRET-KEY-1OTHER\n", $this->outside.'/backup.tar.age'))->toThrow(RuntimeException::class, 'no identity matched');
});

it('leaves nothing behind when writing or testing the identity fails', function (): void {
    putenv('FAKE_AGE_FAIL=1');

    try {
        expect(fn () => new KeySetup(new AgeCli(base_path('tests/Fixtures/bin/fake-age'), base_path('tests/Fixtures/bin/fake-age-keygen')))->create($this->identity, BACKUP_PASSPHRASE))
            ->toThrow(InstanceException::class, 'Writing or testing the backup identity failed');
    } finally {
        putenv('FAKE_AGE_FAIL');
    }

    expect(file_exists($this->identity))->toBeFalse()
        ->and(fn () => new AgeCli('/nonexistent/age')->encryptToRecipients('x', ['age1'.str_repeat('a', 58)], $this->outside.'/x'))->toThrow(RuntimeException::class)
        ->and(fn () => fakeAge()->encryptToRecipients('x', [], $this->outside.'/x'))->toThrow(RuntimeException::class, 'No age recipient')
        ->and(fn () => new AgeCli(base_path('tests/Fixtures/bin/fake-age'), '/usr/bin/false')->generateIdentity())->toThrow(RuntimeException::class, 'age-keygen failed');
});

it('refuses an identity path inside the instance directory, a relative one, an existing file or a missing directory', function (string $case): void {
    $directory = TemporaryInstance::directory();
    $path = match ($case) {
        'inside' => $directory->root.'/backup-identity.age',
        'inside backups' => $directory->root.'/backups/id.age',
        'relative' => 'backup-identity.age',
        'existing' => $this->identity,
        'missing directory' => $this->outside.'/nope/id.age',
    };
    if ($case === 'existing') {
        file_put_contents($path, 'old identity');
    }
    if ($case === 'inside backups') {
        mkdir($directory->root.'/backups', 0700);
    }
    $codes = ['inside' => 'identity_inside_instance', 'inside backups' => 'identity_inside_instance', 'relative' => 'invalid_identity_path', 'existing' => 'identity_exists', 'missing directory' => 'invalid_identity_path'];

    expect(fn () => KeySetup::checkPath($path, $directory))->toThrow(function (InvalidInput $e) use ($codes, $case): void {
        expect($e->errorCode)->toBe($codes[$case]);
    });
})->with(['inside', 'inside backups', 'relative', 'existing', 'missing directory']);

it('seals a plain backup in place to the recipients and leaves an encrypted one alone', function (): void {
    $directory = TemporaryInstance::directory();
    $store = new BackupStore($directory);
    $plain = $directory->ensureDirectory('backups/safety-upgrade-20261019T033000Z');
    file_put_contents($plain.'/database.dump', 'PGDMP');
    file_put_contents($plain.'/SHA256SUMS', 'sums');
    file_put_contents($plain.'/keep', '');
    $recipient = 'age1'.str_repeat('b', 58);
    $sealer = new BackupSealer($store, fakeAge());

    $sealed = $sealer->seal('safety-upgrade-20261019T033000Z', [$recipient]);
    $again = $sealer->seal('safety-upgrade-20261019T033000Z', [$recipient]);

    expect($sealed)->toMatchArray(['encrypted' => true, 'changed' => true])
        ->and(array_values(array_diff(scandir($plain) ?: [], ['.', '..'])))->toBe(['backup.tar.age', 'keep'])
        ->and((string) file_get_contents($plain.'/backup.tar.age'))->toStartWith('fake-age:to:'.$recipient)
        ->and($again['changed'])->toBeFalse()
        ->and(fn () => $sealer->seal('manual-20261019T033000Z', [$recipient]))->toThrow(RuntimeException::class, 'no complete backup')
        ->and(fn () => $sealer->seal('safety-upgrade-20261019T033000Z', []))->not->toThrow(Throwable::class);
});

it('turns encryption on with config set: identity written, only the recipient in the state, the backup service recreated', function (): void {
    app()->instance(AgeCli::class, fakeAge());
    $fixture = new ConfigFixture;
    file_put_contents($this->outside.'/passphrase.txt', BACKUP_PASSPHRASE."\n");

    [$code, $json] = $fixture->set('backup-encryption', 'on', ['--identity-out' => $this->identity, '--passphrase-file' => $this->outside.'/passphrase.txt']);
    $state = $fixture->state();
    $journal = json_encode($fixture->archivedJournal()->toArray());
    [$againCode, $again] = $fixture->set('backup-encryption', 'on');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['key' => 'backup-encryption', 'from' => 'off', 'to' => 'on'])
        ->and($json['data']['encryption'])->toBe(['enabled' => true, 'recipients' => $state->recipients(), 'identity' => $this->identity])
        ->and($json['data']['services']['recreated'])->toBe(['backup'])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('backup_identity_written')
        ->and($state->recipients())->toHaveCount(1)
        ->and((string) $fixture->instance->directory->read('env/backup.env'))->toContain($state->recipients()[0])
        ->and(json_encode($json).$journal)->not->toContain(BACKUP_PASSPHRASE)->not->toContain('AGE-SECRET-KEY')
        ->and($againCode)->toBe(0)->and($again['changed'])->toBeFalse();

    [$offCode, $off] = $fixture->set('backup-encryption', 'off');
    expect($offCode)->toBe(0)->and($off['data']['to'])->toBe('off')->and($fixture->state()->recipients())->toBe([])
        ->and(file_exists($this->identity))->toBeTrue();
});

it('refuses backup-encryption on without an identity path or passphrase, inside the instance directory, or with a weak passphrase', function (): void {
    app()->instance(AgeCli::class, fakeAge());
    $fixture = new ConfigFixture;
    file_put_contents($this->outside.'/passphrase.txt', BACKUP_PASSPHRASE);
    file_put_contents($this->outside.'/short.txt', 'short');
    $inside = $fixture->instance->directory->root.'/id.age';

    $cases = [
        'identity_required' => ['--passphrase-file' => $this->outside.'/passphrase.txt'],
        'identity_inside_instance' => ['--identity-out' => $inside, '--passphrase-file' => $this->outside.'/passphrase.txt'],
        'weak_passphrase' => ['--identity-out' => $this->identity, '--passphrase-file' => $this->outside.'/short.txt'],
        'invalid_flag' => ['--identity-out' => $this->identity, '--passphrase-file' => $this->outside.'/passphrase.txt', '--passphrase-stdin' => true],
    ];
    foreach ($cases as $error => $options) {
        [$code, $json] = $fixture->set('backup-encryption', 'on', $options);
        expect($code)->toBe(2, $error)->and($json['errors'][0]['code'])->toBe($error);
    }
    [$otherCode, $other] = $fixture->set('port', '8090', ['--identity-out' => $this->identity, '--yes' => true]);
    [$valueCode, $value] = $fixture->set('backup-encryption', 'maybe');

    expect($otherCode)->toBe(2)->and($other['errors'][0]['code'])->toBe('invalid_flag')
        ->and($valueCode)->toBe(2)->and($value['errors'][0]['code'])->toBe('invalid_value')
        ->and(file_exists($this->identity))->toBeFalse()->and(file_exists($inside))->toBeFalse()
        ->and($fixture->state()->recipients())->toBe([]);
});
