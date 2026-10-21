<?php

declare(strict_types=1);

use App\Domain\Instance\Runtime\LocalCaRoot;
use Illuminate\Config\Repository;
use Tests\Support\Instance\LocalCaFixture;

/*
 * Plan U14 / R19 (ADR 0082): the local CA's public root as the phone trust page reads it from the runtime directory.
 * Only one CA certificate in a small regular file passes; anything else, a key above all, reads as "no root".
 */

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/fieldset-local-ca-'.bin2hex(random_bytes(6));
    mkdir($this->directory, 0755);
    $this->config = new Repository(['instance' => ['runtime_path' => $this->directory]]);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->directory));
});

it('reads the root from the runtime directory with its PEM, fingerprint and common name', function (): void {
    $pem = LocalCaFixture::root();
    LocalCaFixture::install($this->directory, $pem);

    $root = LocalCaRoot::fromRuntime($this->config);

    expect($root)->toBeInstanceOf(LocalCaRoot::class)
        ->and($root?->pem())->toBe($pem)
        ->and($root?->fingerprint())->toBe(LocalCaFixture::fingerprint($pem))
        ->and($root?->subject())->toBe(LocalCaFixture::ROOT_NAME);
});

it('formats the fingerprint exactly as openssl x509 -fingerprint -sha256 prints it', function (): void {
    $pem = LocalCaFixture::root();
    $path = LocalCaFixture::install($this->directory, $pem);
    $fingerprint = LocalCaRoot::fromFile($path)?->fingerprint();

    expect($fingerprint)->toMatch('/\A[0-9A-F]{2}(:[0-9A-F]{2}){31}\z/D');

    exec('openssl x509 -noout -fingerprint -sha256 -in '.escapeshellarg($path).' 2>/dev/null', $output, $status);
    if ($status !== 0 || $output === []) {
        $this->markTestIncomplete('No openssl binary to compare with; the format check above still ran.');
    }
    expect($fingerprint)->toBe(substr((string) $output[0], (int) strpos((string) $output[0], '=') + 1));
});

it('ends the PEM with exactly one newline and accepts CRLF line endings', function (): void {
    $pem = LocalCaFixture::root();
    $crlf = str_replace("\n", "\r\n", $pem);

    expect(LocalCaRoot::fromPem("\n".$pem."\n\n")?->pem())->toBe($pem)
        ->and(LocalCaRoot::fromPem($crlf)?->fingerprint())->toBe(LocalCaFixture::fingerprint($pem));
});

it('has no root without a configured runtime directory or without the file', function (?string $path): void {
    expect(LocalCaRoot::fromRuntime(new Repository(['instance' => ['runtime_path' => $path]])))->toBeNull();
})->with(['not configured' => [null], 'blank' => ['  '], 'missing directory' => ['/nonexistent/fieldset-runtime']]);

it('has no root when the runtime directory holds no certificate file', function (): void {
    expect(LocalCaRoot::fromRuntime($this->config))->toBeNull();
});

it('refuses a file larger than 16 KiB even when it starts with a valid root', function (): void {
    LocalCaFixture::install($this->directory, LocalCaFixture::root().str_repeat("\n", LocalCaRoot::MAX_BYTES));

    expect(LocalCaRoot::fromRuntime($this->config))->toBeNull();
});

it('refuses anything but exactly one CA certificate', function (Closure $content): void {
    LocalCaFixture::install($this->directory, $content());

    expect(LocalCaRoot::fromRuntime($this->config))->toBeNull();
})->with([
    'two certificates' => [fn (): string => LocalCaFixture::root().LocalCaFixture::root()],
    'not a CA' => [fn (): string => LocalCaFixture::leaf()],
    'a key after the root' => [fn (): string => LocalCaFixture::root().LocalCaFixture::privateKey()],
    'a key before the root' => [fn (): string => LocalCaFixture::privateKey().LocalCaFixture::root()],
    'only a key' => [LocalCaFixture::privateKey(...)],
    'PRIVATE KEY in a comment' => [fn (): string => "# PRIVATE KEY\n".LocalCaFixture::root()],
    'text around the block' => [fn (): string => "Bag Attributes\n".LocalCaFixture::root()],
    'a broken body' => [fn (): string => "-----BEGIN CERTIFICATE-----\nTUlJQm5vdCBhIGNlcnQ=\n-----END CERTIFICATE-----\n"],
    'empty' => [fn (): string => ''],
    'garbage' => [fn (): string => random_bytes(512)],
]);

it('refuses a symlink and a directory in place of the file', function (string $kind): void {
    $target = $this->directory.'/elsewhere.crt';
    file_put_contents($target, LocalCaFixture::root());
    $path = $this->directory.'/'.LocalCaRoot::FILE;
    $kind === 'symlink' ? symlink($target, $path) : mkdir($path);

    expect(LocalCaRoot::fromRuntime($this->config))->toBeNull()
        ->and(LocalCaRoot::fromFile($target))->toBeInstanceOf(LocalCaRoot::class);
})->with(['symlink', 'directory']);

it('leaves no OpenSSL errors behind after a refused file', function (): void {
    while (openssl_error_string() !== false) {
        // start clean
    }

    expect(LocalCaRoot::fromPem("-----BEGIN CERTIFICATE-----\nTUlJQm5vdCBhIGNlcnQ=\n-----END CERTIFICATE-----\n"))->toBeNull()
        ->and(openssl_error_string())->toBeFalse();
});
