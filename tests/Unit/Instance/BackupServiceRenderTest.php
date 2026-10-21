<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\PortableSettings;
use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\StackFile;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\TemporaryInstance;

/*
 * The rendered backup service (KTD15, plan U16, ADR 0084): container root with four capabilities, no new privileges, a
 * read-only root, the storage volume read-only, of the instance directory only the marker, locks/ and backups/, never
 * the socket; the render creates its bind mounts and `staging/backup/instance.json`; the stack file has no backup service.
 */

afterEach(fn () => TemporaryInstance::cleanup());

it('renders the backup service hardened, without the socket, with its 128 MiB slot on small', function (): void {
    $service = ComposeFixtures::render()->service('backup');

    expect($service['command'])->toBe(['fsa', 'backup-daemon'])
        ->and($service['user'])->toBe('0:0')
        ->and($service['cap_drop'])->toBe(['ALL'])
        ->and($service['cap_add'])->toBe(['CHOWN', 'DAC_OVERRIDE', 'DAC_READ_SEARCH', 'FOWNER'])
        ->and($service['security_opt'])->toBe(['no-new-privileges:true'])
        ->and($service['read_only'])->toBeTrue()
        ->and($service['tmpfs'])->toBe(['/tmp'])
        ->and($service['mem_limit'])->toBe('128m')
        ->and($service['networks'])->toBe(['default'])
        ->and($service['depends_on'])->toBe(['postgres' => ['condition' => 'service_healthy']])
        ->and($service['stop_grace_period'])->toBe('30s')
        ->and($service['volumes'])->toBe([
            ['type' => 'volume', 'source' => 'app-storage', 'target' => '/var/www/html/storage/app', 'read_only' => true],
            ['type' => 'bind', 'source' => './.fieldset-instance', 'target' => '/fsa/instance/.fieldset-instance', 'read_only' => true, 'bind' => ['create_host_path' => false]],
            ['type' => 'bind', 'source' => './locks', 'target' => '/fsa/instance/locks', 'read_only' => false, 'bind' => ['create_host_path' => false]],
            ['type' => 'bind', 'source' => './backups', 'target' => '/fsa/instance/backups', 'read_only' => false, 'bind' => ['create_host_path' => false]],
            ['type' => 'bind', 'source' => './staging/backup', 'target' => '/fsa/instance/staging/backup', 'read_only' => false, 'bind' => ['create_host_path' => false]],
        ])
        ->and(json_encode($service))->not->toContain('docker.sock')
        ->and(ComposeFixtures::render()->environments['backup'])->toHaveKey('BACKUP_HMAC_KEY')->not->toHaveKey('APP_KEY');
});

it('creates backups/, locks/ and staging/backup/ for the owner and writes the portable settings outside backups/', function (): void {
    $directory = TemporaryInstance::directory();
    $state = ComposeFixtures::state(['smtp_host' => 'smtp.example.com', 'mail' => 'smtp', 'port' => 8090, 'lan_address' => '192.168.1.20'], $directory->root)->withRecipients(['age1'.str_repeat('a', 58)]);
    $directory->write('backups/.instance.json', '{"recipients": ["age1planted"]}'); // a pre-release copy, or a planted one

    new ComposeRenderer()->write($directory, $state, ComposeFixtures::secrets());
    $meta = json_decode((string) $directory->read('staging/backup/instance.json'), true);

    expect(substr(sprintf('%o', fileperms($directory->path('backups'))), -3))->toBe('700')
        ->and(substr(sprintf('%o', fileperms($directory->path('locks'))), -3))->toBe('700')
        ->and(substr(sprintf('%o', fileperms($directory->path('staging'))), -3))->toBe('700')
        ->and(substr(sprintf('%o', fileperms($directory->path('staging/backup'))), -3))->toBe('700')
        ->and(substr(sprintf('%o', fileperms($directory->path('staging/backup/instance.json'))), -3))->toBe('600')
        ->and($directory->exists('backups/.instance.json'))->toBeFalse()
        ->and($meta['instance'])->toBe(['uuid' => ComposeFixtures::UUID, 'project' => $state->project(), 'version' => '2.0.0', 'mode' => 'local'])
        ->and($meta['url'])->toBe('http://localhost:8090')
        ->and($meta['recipients'])->toBe($state->recipients())
        ->and($meta['settings'])->toBe(['mail' => 'smtp', 'smtp_host' => 'smtp.example.com'])
        ->and(array_intersect(array_keys($meta['settings']), ['port', 'lan_address', 'edge_subnet', 'tunnel_subnet', 'proxy_network']))->toBe([])
        ->and(PortableSettings::KEYS)->toContain('backup_schedule')->toContain('time_zone');
});

it('keeps the backup service out of the stack file, which documents that backups need fsa', function (): void {
    $stack = StackFile::render(ComposeFixtures::digests(), '2.0.0');

    expect(array_keys($stack->document['services']))->not->toContain('backup')
        ->and($stack->yaml())->toContain('no backups through fsa');
});
