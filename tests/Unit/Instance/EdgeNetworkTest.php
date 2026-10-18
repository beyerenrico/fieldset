<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InputGrammar;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\DomainFixtures;

/*
 * Plan U8 (KTD10; ADR 0075): the edge network's fixed subnet, chosen once per instance without colliding with the
 * host's Docker networks, and the domain-mode settings grammar.
 */

it('places the edge at the second host address of its subnet', function (string $subnet, string $address): void {
    expect(EdgeNetwork::edgeAddress($subnet))->toBe($address);
})->with([
    ['10.213.4.32/28', '10.213.4.34'],
    ['10.213.0.0/28', '10.213.0.2'],
    ['192.168.200.0/24', '192.168.200.2'],
]);

it('keeps the gateway and the edge out of the dynamic range Docker hands out', function (string $subnet, string $range, string $gateway): void {
    $ipam = EdgeNetwork::ipam($subnet);

    expect($ipam)->toBe(['subnet' => $subnet, 'ip_range' => $range, 'gateway' => $gateway])
        ->and(EdgeNetwork::overlaps(EdgeNetwork::edgeAddress($subnet).'/32', $range))->toBeFalse()
        ->and(EdgeNetwork::overlaps($gateway.'/32', $range))->toBeFalse()
        ->and(EdgeNetwork::overlaps($range, $subnet))->toBeTrue();
})->with([
    ['10.213.4.32/28', '10.213.4.40/29', '10.213.4.33'],
    ['192.168.200.0/24', '192.168.200.128/25', '192.168.200.1'],
]);

it('refuses a subnet that is not a private IPv4 CIDR as the edge subnet', function (string $subnet): void {
    expect(fn () => EdgeNetwork::edgeAddress($subnet))->toThrow(InstanceException::class);
})->with(['8.8.8.0/28', '10.213.4.33/28', '10.213.4.32', 'fd00::/64', '10.213.4.32/30']);

it('chooses a /28 in 10.213.0.0/16 from the UUID, the same every time', function (): void {
    $subnet = EdgeNetwork::choose(ComposeFixtures::UUID, []);

    expect($subnet)->toMatch('/^10\.213\.\d{1,3}\.\d{1,3}\/28$/')
        ->and(InputGrammar::isPrivateSubnet($subnet))->toBeTrue()
        ->and(EdgeNetwork::choose(ComposeFixtures::UUID, []))->toBe($subnet)
        ->and(EdgeNetwork::choose('9c4d1e2f-0a1b-4c2d-8e3f-405162738495', []))->not->toBe($subnet);
});

it('skips candidates that overlap an existing Docker network, IPv6 and junk entries never overlap', function (): void {
    $first = EdgeNetwork::choose(ComposeFixtures::UUID, []);
    $second = EdgeNetwork::choose(ComposeFixtures::UUID, [$first]);

    expect($second)->not->toBe($first)
        ->and(EdgeNetwork::overlaps($first, $second))->toBeFalse()
        ->and(EdgeNetwork::choose(ComposeFixtures::UUID, ['172.17.0.0/16', 'fd00::/8', 'junk']))->toBe($first);
});

it('reports exit 3 when every candidate is taken', function (): void {
    try {
        EdgeNetwork::choose(ComposeFixtures::UUID, ['10.0.0.0/8']);
        $this->fail('no subnet should be free');
    } catch (InstanceException $e) {
        expect($e->exitCode)->toBe(ExitCode::PreflightFailed)->and($e->errorCode)->toBe('edge_subnet_unavailable');
    }
});

it('detects overlaps between IPv4 ranges only', function (string $a, string $b, bool $overlaps): void {
    expect(EdgeNetwork::overlaps($a, $b))->toBe($overlaps)
        ->and(EdgeNetwork::overlaps($b, $a))->toBe($overlaps);
})->with([
    'inside' => ['10.213.4.32/28', '10.213.0.0/16', true],
    'adjacent' => ['10.213.4.32/28', '10.213.4.48/28', false],
    'same' => ['172.18.0.0/16', '172.18.0.0/16', true],
    'host bits set' => ['172.18.5.7/16', '172.18.200.0/24', true],
    'disjoint' => ['10.213.4.32/28', '192.168.0.0/16', false],
    'ipv6' => ['10.213.4.32/28', 'fd00::/8', false],
    'junk' => ['10.213.4.32/28', 'not a subnet', false],
]);

it('accepts the domain-mode settings and refuses malformed ones', function (string $key, string $value, bool $valid): void {
    expect(InputGrammar::settingProblem($key, $value) === null)->toBe($valid);
})->with([
    'acme email' => ['acme_email', 'ops@example.com', true],
    'acme email with quote' => ['acme_email', 'o"ps@example.com', false],
    'acme ca https' => ['acme_ca', 'https://pebble:14000/dir', true],
    'acme ca http' => ['acme_ca', 'http://pebble:14000/dir', false],
    'acme ca with credentials' => ['acme_ca', 'https://u:p@ca.example.com/dir', false],
    'acme ca with brace' => ['acme_ca', 'https://ca.example.com/{env.X}', false],
    'acme root digest' => ['acme_ca_root', str_repeat('ab', 32), true],
    'acme root uppercase' => ['acme_ca_root', str_repeat('AB', 32), false],
    'acme root path' => ['acme_ca_root', '/etc/caddy/root.pem', false],
    'edge subnet' => ['edge_subnet', '10.213.4.32/28', true],
    'edge subnet public' => ['edge_subnet', '100.64.0.0/28', false],
    'edge subnet unaligned' => ['edge_subnet', '10.213.4.33/28', false],
    'edge subnet too small' => ['edge_subnet', '10.213.4.32/30', false],
    'edge subnet too large' => ['edge_subnet', '10.0.0.0/8', false],
]);

it('reads the domain-mode settings with their defaults', function (): void {
    $defaults = InstanceSettings::of(DomainFixtures::state());
    $set = InstanceSettings::of(DomainFixtures::state(['acme_email' => 'ops@example.com', 'acme_ca' => InstanceSettings::ACME_STAGING, 'acme_ca_root' => str_repeat('a', 64)]));
    $local = InstanceSettings::of(ComposeFixtures::state(['domain' => 'forms.example.com']));

    expect($defaults->domain())->toBe(DomainFixtures::DOMAIN)
        ->and($defaults->acmeCa())->toBe(InstanceSettings::ACME_PRODUCTION)
        ->and($defaults->acmeEmail())->toBeNull()
        ->and($defaults->acmeCaRoot())->toBeNull()
        ->and($defaults->edgeSubnet())->toBe(DomainFixtures::SUBNET)
        ->and($defaults->catcherUrl())->toBe('https://forms.example.com/_mail/')
        ->and($set->acmeCa())->toBe(InstanceSettings::ACME_STAGING)
        ->and($set->acmeEmail())->toBe('ops@example.com')
        ->and($set->acmeCaRoot())->toBe(str_repeat('a', 64))
        ->and($local->domain())->toBeNull()
        ->and($local->catcherUrl())->toBe('http://localhost:8025')
        ->and(InstanceSettings::of(DomainFixtures::state(ComposeFixtures::smtpSettings()))->catcherUrl())->toBeNull();
});
