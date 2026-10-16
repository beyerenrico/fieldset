<?php

declare(strict_types=1);

use App\Support\Network\PublicAddress;

/* SSRF guard building block (§11) shared by the webhook step and organization SMTP servers. */

it('refuses non-public addresses', function (string $ip): void {
    expect(PublicAddress::isPublic($ip))->toBeFalse();
})->with([
    '127.0.0.1', '127.255.255.254', '10.0.0.1', '10.255.255.255', '172.16.0.1', '192.168.1.1', '169.254.169.254',
    '100.64.0.1', '0.0.0.0', '224.0.0.1', '239.255.255.250', '255.255.255.255', '198.18.0.1', '192.0.2.1',
    '::', '::1', '[::1]', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1',
    '64:ff9b::7f00:1', '2002:7f00:1::', '2001:db8::1', 'not-an-ip', '',
]);

it('accepts globally routable addresses', function (string $ip): void {
    expect(PublicAddress::isPublic($ip))->toBeTrue();
})->with(['8.8.8.8', '93.184.215.14', '2606:4700:4700::1111', '2a00:1450:4001:81d::200e']);

it('requires every resolved address to be public', function (): void {
    expect(PublicAddress::allPublic(['8.8.8.8', '1.1.1.1']))->toBeTrue()
        ->and(PublicAddress::allPublic(['8.8.8.8', '10.0.0.1']))->toBeFalse()
        ->and(PublicAddress::allPublic([]))->toBeFalse();
});
