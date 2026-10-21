<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\KeyFingerprint;
use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Restore\AppKeys;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSecrets;
use Tests\Support\Instance\BackupSetFixture;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\TemporaryInstance;

/*
 * What a restore changes in the rendered stack (plan U17, ADR 0085): the fresh storage volume it swapped in is declared
 * as an existing volume under the same key, so app, queue, scheduler and the backup service mount it; the APP keys a
 * restored backup's data may be encrypted under become APP_PREVIOUS_KEYS, and the backup service lists every key's
 * fingerprint, the current one first.
 */

it('renders Compose\'s own storage volume until a restore names a fresh one', function (): void {
    $plain = ComposeFixtures::render();
    $restored = ComposeFixtures::render(['storage_volume' => 'fieldset-srv-1a2b_app-storage-20261019t120000z']);
    $mounts = static fn (array $document, string $service): array => array_values(array_filter(
        $document['services'][$service]['volumes'],
        static fn (array $volume): bool => $volume['source'] === 'app-storage',
    ));

    expect($plain->document['volumes']['app-storage'])->not->toHaveKey('name')
        ->and($restored->document['volumes']['app-storage'])->toBe(['name' => 'fieldset-srv-1a2b_app-storage-20261019t120000z', 'external' => true])
        ->and($mounts($restored->document, 'app'))->toHaveCount(1)
        ->and($mounts($restored->document, 'queue'))->toHaveCount(1)
        ->and($mounts($restored->document, 'backup'))->toHaveCount(1);
});

it('renders the previous APP keys for the app and every fingerprint for the backup service', function (): void {
    $secrets = ComposeFixtures::secrets()->withPreviousKeys([BackupSetFixture::OTHER_KEY, ComposeFixtures::APP_KEY, BackupSetFixture::OTHER_KEY]);
    $rendered = new ComposeRenderer()->render(ComposeFixtures::state(), $secrets);

    expect($secrets->previousKeys)->toBe([BackupSetFixture::OTHER_KEY])
        ->and($rendered->environments['app']['APP_PREVIOUS_KEYS'])->toBe(BackupSetFixture::OTHER_KEY)
        ->and($rendered->environments['backup']['BACKUP_APP_FINGERPRINTS'])->toBe(KeyFingerprint::of(ComposeFixtures::APP_KEY).','.KeyFingerprint::of(BackupSetFixture::OTHER_KEY))
        ->and(ComposeFixtures::render()->environments['app'])->not->toHaveKey('APP_PREVIOUS_KEYS');
});

it('reads the previous keys back from env/app.env and redacts each of them', function (): void {
    $directory = TemporaryInstance::directory();
    $env = new EnvFiles($directory);
    $env->write('app', ['APP_KEY' => ComposeFixtures::APP_KEY, 'DB_PASSWORD' => 'x', 'APP_PREVIOUS_KEYS' => BackupSetFixture::OTHER_KEY.','.BackupSetFixture::APP_KEY]);

    expect(InstanceSecrets::fromEnvFiles($env)?->previousKeys)->toBe([BackupSetFixture::OTHER_KEY, BackupSetFixture::APP_KEY])
        ->and($env->secretValues())->toContain(BackupSetFixture::OTHER_KEY, BackupSetFixture::APP_KEY);
});

it('reads APP keys from a key file: one per line or an env file, and refuses anything else', function (): void {
    $lines = AppKeys::fromFile(BackupSetFixture::APP_KEY."\n# comment\n".BackupSetFixture::OTHER_KEY."\n");
    $env = AppKeys::fromFile("APP_NAME=x\nAPP_KEY='".BackupSetFixture::APP_KEY."'\nAPP_PREVIOUS_KEYS=".BackupSetFixture::OTHER_KEY."\nDB_PASSWORD=secret\n");

    expect($lines->keys)->toBe([BackupSetFixture::APP_KEY, BackupSetFixture::OTHER_KEY])
        ->and($env->keys)->toBe([BackupSetFixture::APP_KEY, BackupSetFixture::OTHER_KEY])
        ->and($lines->byFingerprint(KeyFingerprint::of(BackupSetFixture::OTHER_KEY)))->toBe(BackupSetFixture::OTHER_KEY)
        ->and($lines->hmacKeys()[0]['hmac_key'])->toBe('base64:'.base64_encode(BackupSetFixture::hmacKey(BackupSetFixture::APP_KEY)))
        ->and(fn () => AppKeys::fromFile("not a key\n"))->toThrow(InvalidInput::class)
        ->and(fn () => AppKeys::fromFile(''))->toThrow(InvalidInput::class)
        ->and(fn () => AppKeys::fromFile('APP_KEY=base64:short'))->toThrow(InvalidInput::class);
});
