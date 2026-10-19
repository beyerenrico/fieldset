<?php

declare(strict_types=1);

namespace App\Domain\Instance\LocalCa;

use App\Domain\Instance\Compose\ImageRole;
use App\Domain\Instance\Compose\Services\EdgeService;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Runtime\LocalCaRoot;
use App\Domain\Instance\State\InstanceState;

/**
 * The local CA on the edge's certificate volume (R19, ADR 0082). Caddy keeps its internal CA under
 * `/data/caddy/pki/authorities/local/` (root and intermediate, each with its key, 0600 for Caddy's UID) and the leaf
 * certificates under `/data/caddy/certificates/local/`. fsa never opens the volume itself: a throwaway container of the
 * pinned Caddy image, as Caddy's UID and without a network, reads the public root or deletes the CA.
 *
 * - `root()`: the root certificate only, checked to be exactly one CA certificate and nothing else (never a key).
 * - `deleteKey()`: turning the local CA off removes the whole CA (keys included) and the certificates it issued; the
 *   volume stays for a later domain mode. Turning it on again creates a new CA, which phones must trust anew.
 */
final readonly class LocalCaStore
{
    public const string ROLE = 'local-ca';

    public const string CA_DIRECTORY = EdgeService::DATA_PATH.'/pki';

    public const string ROOT_FILE = self::CA_DIRECTORY.'/authorities/local/root.crt';

    /** The leaf certificates the CA issued (the LAN address's). */
    public const string CERTIFICATES = EdgeService::DATA_PATH.'/certificates/local';

    private const float TIMEOUT = 60.0;

    public function __construct(private DockerGateway $docker) {}

    /** The CA's public root certificate (PEM), or null while Caddy has not created it. */
    public function root(InstanceState $state): ?string
    {
        $outcome = $this->docker->runContainer($this->spec($state, 'cat', [self::ROOT_FILE], readOnly: true), null, self::TIMEOUT);
        if (! $outcome->successful()) {
            return null;
        }

        return self::publicRoot($outcome->stdout);
    }

    /** Deletes the CA (root and intermediate with their keys) and its certificates from the volume. */
    public function deleteKey(InstanceState $state): void
    {
        $outcome = $this->docker->runContainer($this->spec($state, 'rm', ['-rf', self::CA_DIRECTORY, self::CERTIFICATES], readOnly: false), null, self::TIMEOUT);
        if (! $outcome->successful()) {
            throw new InstanceException('The local CA could not be deleted from the edge\'s certificate volume: '.$outcome->stderrTail(2), ExitCode::HealthCheckFailed, 'local_ca_delete_failed', 'Run the command again; `fsa uninstall` removes the volume with the instance.', true);
        }
    }

    /** "AB:CD:…": SHA-256 of the DER, as `openssl x509 -fingerprint -sha256` prints it and the trust page shows it. */
    public static function fingerprint(string $pem): string
    {
        return LocalCaRoot::fromPem($pem)?->fingerprint() ?? '';
    }

    /** The PEM when it is exactly one CA certificate and nothing else (the app's own rule, LocalCaRoot); else null. */
    public static function publicRoot(string $pem): ?string
    {
        return LocalCaRoot::fromPem($pem)?->pem();
    }

    /** @param  list<string>  $arguments */
    private function spec(InstanceState $state, string $entrypoint, array $arguments, bool $readOnly): ContainerSpec
    {
        return new ContainerSpec(
            image: $state->digests()[ImageRole::Caddy->value] ?? throw new InstanceException('The instance state pins no Caddy image.', ExitCode::InternalError, 'no_edge_image'),
            command: $arguments,
            labels: Labels::forInstance($state->uuid(), self::ROLE),
            volumes: [EdgeService::volumeName($state->project()).':'.EdgeService::DATA_PATH.($readOnly ? ':ro' : '')],
            network: 'none',
            user: EdgeService::USER,
            entrypoint: $entrypoint,
        );
    }
}
