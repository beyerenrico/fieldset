<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Runtime\LocalCaRoot;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Certificates for the phone trust tests (R19, ADR 0082): a self-signed EC root as Caddy's internal CA makes it
 * (basicConstraints CA:TRUE), a non-CA certificate, a private key, and `local-ca-root.crt` as `fsa` writes it into the
 * runtime directory (0644). The OpenSSL config is written once per process, so no system openssl.cnf is needed.
 */
final class LocalCaFixture
{
    public const string ROOT_NAME = 'Fieldset local CA - 2026 ECC Root';

    private const string CONFIG = <<<'CNF'
        [req]
        distinguished_name = dn
        [dn]
        [v3_ca]
        basicConstraints = critical,CA:TRUE,pathlen:1
        keyUsage = critical,keyCertSign,cRLSign
        subjectKeyIdentifier = hash
        [v3_leaf]
        basicConstraints = critical,CA:FALSE
        keyUsage = critical,digitalSignature
        CNF;

    private static ?string $config = null;

    public static function root(string $commonName = self::ROOT_NAME): string
    {
        return self::certificate($commonName, 'v3_ca');
    }

    /** A certificate that is not a CA (an edge's leaf certificate). */
    public static function leaf(string $commonName = '192.168.1.20'): string
    {
        return self::certificate($commonName, 'v3_leaf');
    }

    public static function privateKey(): string
    {
        openssl_pkey_export(self::key(), $pem, null, ['config' => self::config()]);

        return (string) $pem;
    }

    /** Writes `local-ca-root.crt` into a runtime directory, replaced by rename like `fsa` does. */
    public static function install(string $directory, string $content): string
    {
        $path = $directory.'/'.LocalCaRoot::FILE;
        $temporary = $directory.'/.'.LocalCaRoot::FILE.'.'.bin2hex(random_bytes(4));
        file_put_contents($temporary, $content);
        chmod($temporary, 0644);
        rename($temporary, $path);

        return $path;
    }

    /**
     * The fingerprint derived independently of OpenSSL's parser: SHA-256 over the base64-decoded DER of the PEM block,
     * uppercase pairs joined by ':' (the format of `openssl x509 -noout -fingerprint -sha256`).
     */
    public static function fingerprint(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);
        $der = base64_decode((string) $body, true);
        if ($der === false) {
            throw new RuntimeException('Not a PEM certificate.');
        }

        return implode(':', str_split(strtoupper(hash('sha256', $der)), 2));
    }

    private static function certificate(string $commonName, string $extensions): string
    {
        $key = self::key();
        $options = ['config' => self::config(), 'digest_alg' => 'sha256'];
        $csr = openssl_csr_new(['commonName' => $commonName], $key, $options);
        $certificate = $csr === false || $csr === true ? false : openssl_csr_sign($csr, null, $key, 30, [...$options, 'x509_extensions' => $extensions], random_int(1, PHP_INT_MAX));
        if ($certificate === false || ! openssl_x509_export($certificate, $pem)) {
            throw new RuntimeException('Could not create the test certificate: '.openssl_error_string());
        }

        return (string) $pem;
    }

    private static function key(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => self::config()]);

        return $key instanceof OpenSSLAsymmetricKey ? $key : throw new RuntimeException('Could not create a test key.');
    }

    private static function config(): string
    {
        if (self::$config === null || ! is_file(self::$config)) {
            self::$config = sys_get_temp_dir().'/fsa-local-ca-test-'.getmypid().'.cnf';
            file_put_contents(self::$config, self::CONFIG."\n");
        }

        return self::$config;
    }
}
