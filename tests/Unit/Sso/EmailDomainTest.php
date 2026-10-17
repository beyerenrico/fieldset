<?php

declare(strict_types=1);

use App\Domain\Identity\Sso\Login\EmailDomain;

it('extracts the lower-case punycode domain of an address', function (?string $email, ?string $domain): void {
    expect(EmailDomain::of($email))->toBe($domain);
})->with([
    ['Jane@ACME.example', 'acme.example'],
    [' jane@acme.example. ', 'acme.example'],
    ['"a@b"@acme.example', 'acme.example'],
    ['jörg@müller.example', 'xn--mller-kva.example'],
    ['jane@sub.acme.example', 'sub.acme.example'],
    ['no-at-sign', null],
    ['@acme.example', null],
    ['jane@', null],
    ['jane@localhost', null],
    ['jane@-bad-.example', null],
    [null, null],
]);
