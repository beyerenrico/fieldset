<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The host's view of a local-CA instance for J6 (R19, ADR 0082): the LAN address the journeys use, TLS to it without
 * SNI against the instance's root, plain HTTP on port 80, the SPKI hashes Chromium is told to trust (standing in for a
 * phone that installed the CA), and the Playwright run of tests/e2e/fsa-phone-trust.spec.ts.
 */
final class LocalCaClient
{
    /** The first private IPv4 address the shim reports for this host (the same rule as fsa's best candidate), or null. */
    public static function lanAddress(): ?string
    {
        $process = new Process(['/bin/sh', '-c', '. "$1"; fsa_lan_addresses', 'sh', TestImage::repository().'/distribution/shim.sh'], null, ['FIELDSET_SHIM_LIBRARY' => '1']);
        $process->run();
        $usable = array_values(array_filter(preg_split('/\s+/', trim($process->getOutput())) ?: [], static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && ! str_starts_with($ip, '127.') && ! str_starts_with($ip, '169.254.')));
        $private = array_values(array_filter($usable, static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false));

        return $private[0] ?? $usable[0] ?? null;
    }

    /**
     * TLS to <address>:443 without SNI, verified against the root (and the IP as the peer name).
     *
     * @return array{ok: bool, error: string, san: string|null, chain: list<string>}
     */
    public static function handshake(string $address, string $rootFile): array
    {
        $context = stream_context_create(['ssl' => [
            'cafile' => $rootFile, 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $address,
            'SNI_enabled' => false, 'capture_peer_cert' => true, 'capture_peer_cert_chain' => true,
        ]]);
        $socket = @stream_socket_client("ssl://{$address}:443", $errno, $error, 20, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return ['ok' => false, 'error' => (string) $error, 'san' => null, 'chain' => []];
        }
        $params = stream_context_get_params($socket)['options']['ssl'];
        $leaf = openssl_x509_parse($params['peer_certificate']);
        $chain = array_map(static function ($certificate): string {
            openssl_x509_export($certificate, $pem);

            return $pem;
        }, $params['peer_certificate_chain'] ?? []);

        return ['ok' => true, 'error' => '', 'san' => is_array($leaf) ? ($leaf['extensions']['subjectAltName'] ?? null) : null, 'chain' => $chain];
    }

    /**
     * Base64 SHA-256 of the SubjectPublicKeyInfo of each certificate, as Chromium's
     * --ignore-certificate-errors-spki-list takes them.
     *
     * @param  list<string>  $pems
     */
    public static function spki(array $pems): string
    {
        $hashes = [];
        foreach ($pems as $pem) {
            $details = openssl_pkey_get_details(openssl_pkey_get_public($pem) ?: throw new RuntimeException('No public key in a certificate.'));
            $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', (string) ($details['key'] ?? '')) ?? '', true);
            $hashes[] = base64_encode(hash('sha256', (string) $der, true));
        }

        return implode(',', array_unique($hashes));
    }

    /**
     * Plain HTTP to port 80 of the address (no redirects followed).
     *
     * @return array{status: int, location: string, body: string, headers: array<string, string>}
     */
    public static function http(string $address, string $path): array
    {
        $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => 20]]);
        $body = @file_get_contents("http://{$address}{$path}", false, $context);
        $headers = [];
        $status = 0;
        foreach (http_get_last_response_headers() ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+ (\d{3})/', $line, $match) === 1) {
                $status = (int) $match[1];

                continue;
            }
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }

        // A connection that fails keeps PHP's own message as the body, for the evidence.
        return ['status' => $status, 'location' => $headers['location'] ?? '', 'body' => is_string($body) ? $body : error_get_last()['message'] ?? '', 'headers' => $headers];
    }

    /**
     * tests/e2e/fsa-phone-trust.spec.ts with Chromium trusting the CA (project `fsa-instance`). The spec is named: the
     * project also matches the instance smoke spec (U15, J10), which skips without its own environment.
     *
     * @param  array<string, string>  $env
     */
    public static function playwright(array $env): Process
    {
        $repository = TestImage::repository();
        $binary = $repository.'/node_modules/.bin/playwright';
        if (! is_file($binary)) {
            throw new RuntimeException("Playwright is not installed ({$binary}); run `npm ci` first.");
        }
        $browsers = is_dir($repository.'/storage/playwright') ? $repository.'/storage/playwright' : dirname((string) realpath($repository.'/node_modules')).'/storage/playwright';
        $process = new Process([$binary, 'test', 'tests/e2e/fsa-phone-trust.spec.ts', '--project=fsa-instance', '--reporter=line'], $repository, ['PLAYWRIGHT_BROWSERS_PATH' => $browsers, ...$env]);
        $process->setTimeout(600);
        $process->run();

        return $process;
    }
}
