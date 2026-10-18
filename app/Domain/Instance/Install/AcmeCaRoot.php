<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Compose\CaddyfileRenderer;
use App\Domain\Instance\Compose\Services\EdgeService;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;

/**
 * The custom root for the ACME directory's HTTPS (`--acme-ca-root-file`, ADR 0075): a private ACME CA such as
 * step-ca, or Pebble in the journeys (its HTTPS CA, not its issuing CA; plan KTD10). Only PEM certificates are
 * accepted, at most 64 KiB; the file lands next to the Caddyfile (0644, public data) and the state keeps its SHA-256.
 */
final class AcmeCaRoot
{
    public const string FILE = EdgeService::DIRECTORY.'/'.CaddyfileRenderer::ACME_ROOT_FILE;

    private const int MAX_BYTES = 65536;

    private const string BLOCK = '/-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+\/=\s]+?-----END CERTIFICATE-----/';

    /**
     * Reads and checks the file a flag names (the launcher mounted its directory read-only).
     *
     * @throws InvalidInput
     */
    public static function read(string $path): string
    {
        $contents = is_file($path) && is_readable($path) && filesize($path) <= self::MAX_BYTES ? file_get_contents($path) : false;
        if (! is_string($contents)) {
            throw new InvalidInput('--acme-ca-root-file must name a readable PEM file of at most 64 KiB.', 'invalid_flag', 'Pass the CA certificate of the ACME directory as a PEM file.');
        }

        return self::validated($contents);
    }

    /** @throws InvalidInput when the text is anything but one or more PEM certificates OpenSSL can parse */
    public static function validated(string $pem): string
    {
        $blocks = preg_match_all(self::BLOCK, $pem, $matches) === false ? [] : $matches[0];
        $rest = trim((string) preg_replace(self::BLOCK, '', $pem));
        if ($blocks === [] || $rest !== '' || ! array_all($blocks, static fn (string $block): bool => @openssl_x509_read($block) !== false)) {
            throw new InvalidInput('--acme-ca-root-file must contain PEM certificates only.', 'invalid_flag', 'Pass the CA certificate of the ACME directory as a PEM file.');
        }

        return implode("\n", $blocks)."\n";
    }

    /** Writes the root next to the Caddyfile, unless the same one is already there. */
    public static function write(InstanceDirectory $directory, string $pem): void
    {
        if ($directory->read(self::FILE) === $pem) {
            return;
        }
        $directory->ensureDirectory(EdgeService::DIRECTORY, 0755);
        $directory->write(self::FILE, $pem, 0644);
    }

    /** Whether the file next to the Caddyfile is the root with this SHA-256 (a resume without the flag). */
    public static function present(InstanceDirectory $directory, string $digest): bool
    {
        $current = $directory->read(self::FILE);

        return $current !== null && hash_equals($digest, hash('sha256', $current));
    }
}
