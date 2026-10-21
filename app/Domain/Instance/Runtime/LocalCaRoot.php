<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use Illuminate\Contracts\Config\Repository;
use OpenSSLCertificate;
use Throwable;

/**
 * The public root certificate of the local CA (R19, ADR 0082), which `fieldset` copies from the edge's Caddy data into the
 * runtime directory as `local-ca-root.crt` (0644) for the phone trust page. Never a key.
 *
 * Accepted only as a regular file (no symlink) of at most 16 KiB that holds exactly one PEM certificate and nothing
 * else, contains no "PRIVATE KEY" anywhere, parses with OpenSSL and is a CA certificate (basicConstraints CA:TRUE).
 * Anything else reads as "no root"; reading never throws.
 */
final readonly class LocalCaRoot
{
    public const string FILE = 'local-ca-root.crt';

    public const int MAX_BYTES = 16_384;

    private const int REGULAR_FILE = 0100000;

    private const int FILE_TYPE_MASK = 0170000;

    /** One PEM certificate block and nothing around it but whitespace. */
    private const string SINGLE_CERTIFICATE = '/\A\s*-----BEGIN CERTIFICATE-----\r?\n[A-Za-z0-9+\/=\r\n]+-----END CERTIFICATE-----\s*\z/D';

    private function __construct(
        private string $pem,
        private string $fingerprint,
        private string $subject,
    ) {}

    /** The root in config('instance.runtime_path'), or null when not configured, missing or not acceptable. */
    public static function fromRuntime(Repository $config): ?self
    {
        $directory = $config->get('instance.runtime_path');
        if (! is_string($directory) || trim($directory) === '') {
            return null;
        }

        return self::fromFile(rtrim($directory, '/').'/'.self::FILE);
    }

    public static function fromFile(string $path): ?self
    {
        try {
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false || ($stat['mode'] & self::FILE_TYPE_MASK) !== self::REGULAR_FILE || $stat['size'] > self::MAX_BYTES) {
                return null;
            }
            $pem = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);

            return is_string($pem) && strlen($pem) <= self::MAX_BYTES ? self::fromPem($pem) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function fromPem(string $pem): ?self
    {
        if (str_contains($pem, 'PRIVATE KEY') || preg_match(self::SINGLE_CERTIFICATE, $pem) !== 1) {
            return null;
        }

        try {
            $certificate = @openssl_x509_read($pem);
            $root = $certificate instanceof OpenSSLCertificate ? self::fromCertificate(trim($pem)."\n", $certificate) : null;
        } catch (Throwable) {
            $root = null;
        }
        while (openssl_error_string() !== false) {
            // Drain OpenSSL's error queue, so a refused file leaves nothing behind for later calls.
        }

        return $root;
    }

    /** The certificate as PEM (one block, trailing newline), exactly what the trust page offers for download. */
    public function pem(): string
    {
        return $this->pem;
    }

    /** SHA-256 of the DER as `openssl x509 -noout -fingerprint -sha256` prints it after `=`: `AB:CD:…` (32 pairs). */
    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /** The root's common name, as the phone lists it in its certificate settings. */
    public function subject(): string
    {
        return $this->subject;
    }

    private static function fromCertificate(string $pem, OpenSSLCertificate $certificate): ?self
    {
        $info = openssl_x509_parse($certificate, true);
        $digest = openssl_x509_fingerprint($certificate, 'sha256');
        if (! is_array($info) || ! is_string($digest) || ! self::isCa($info)) {
            return null;
        }

        return new self($pem, implode(':', str_split(strtoupper($digest), 2)), self::commonName($info));
    }

    /** @param  array<mixed>  $info */
    private static function isCa(array $info): bool
    {
        $extensions = $info['extensions'] ?? null;
        $constraints = is_array($extensions) ? ($extensions['basicConstraints'] ?? null) : null;

        return is_string($constraints) && preg_match('/(^|[\s,])CA:TRUE([\s,]|$)/i', $constraints) === 1;
    }

    /** @param  array<mixed>  $info */
    private static function commonName(array $info): string
    {
        $subject = $info['subject'] ?? null;
        $name = is_array($subject) ? ($subject['CN'] ?? null) : null;
        if (is_array($name)) {
            $name = end($name);
        }

        return is_string($name) && trim($name) !== '' ? trim($name) : (is_string($info['name'] ?? null) ? $info['name'] : '');
    }
}
