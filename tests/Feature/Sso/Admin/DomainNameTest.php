<?php

declare(strict_types=1);

use App\Domain\Identity\Sso\Domains\DomainName;
use App\Domain\Identity\Sso\Domains\InvalidDomainName;

it('normalizes a domain to lower-case punycode without a trailing dot', function (string $input, string $expected): void {
    expect(DomainName::normalize($input))->toBe($expected);
})->with([
    'plain' => ['acme.example', 'acme.example'],
    'upper case and spaces' => ['  ACME.Example ', 'acme.example'],
    'trailing dot' => ['acme.example.', 'acme.example'],
    'subdomain' => ['Mail.Acme.Example', 'mail.acme.example'],
    'IDN' => ['Bücher.example', 'xn--bcher-kva.example'],
    'punycode stays' => ['xn--bcher-kva.example', 'xn--bcher-kva.example'],
    'hyphen' => ['my-company.de', 'my-company.de'],
    'below a public suffix' => ['acme.co.uk', 'acme.co.uk'],
]);

it('rejects anything that is not an exact registrable host name', function (string $input, string $reason): void {
    expect(fn () => DomainName::normalize($input))
        ->toThrow(fn (InvalidDomainName $e) => expect($e->reason)->toBe($reason));
})->with([
    'empty' => ['  ', 'invalid'],
    'email address' => ['boss@acme.example', 'invalid'],
    'URL' => ['https://acme.example', 'invalid'],
    'path' => ['acme.example/login', 'invalid'],
    'port' => ['acme.example:443', 'invalid'],
    'wildcard' => ['*.acme.example', 'invalid'],
    'space inside' => ['acme example.com', 'invalid'],
    'underscore' => ['a_b.example', 'invalid'],
    'leading hyphen' => ['-acme.example', 'invalid'],
    'empty label' => ['acme..example', 'invalid'],
    'label too long' => [str_repeat('a', 64).'.example', 'invalid'],
    'name too long' => [implode('.', array_fill(0, 50, 'abcdef')).'.example', 'invalid'],
    'single label' => ['localhost', 'public_suffix'],
    'top-level domain' => ['com', 'public_suffix'],
    'numeric TLD' => ['acme.123', 'invalid'],
    'IPv4' => ['192.168.10.1', 'ip_address'],
    'IPv6' => ['::1', 'ip_address'],
    'bracketed IPv6' => ['[2001:db8::1]', 'ip_address'],
    'multi-label public suffix' => ['co.uk', 'public_suffix'],
    'public suffix upper case' => ['COM.AU', 'public_suffix'],
    'hosting suffix' => ['github.io', 'public_suffix'],
]);
