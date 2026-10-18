<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use App\Domain\Instance\Release\ManifestVerifier;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Local-key signing for the release harness (ADR 0060, ADR 0067) with the official cosign image pinned by
 * digest, the same image `install.sh` verifies with. The key pair is generated per run into the run directory
 * and never committed. Blob signing and verification run with `--network none`: an empty signing config (no
 * Rekor, no TSA) and `--insecure-ignore-tlog` keep Sigstore's public services out of every test. Production
 * signing is keyless in the release workflow; only harness builds accept a local key (R58).
 */
final readonly class Cosign
{
    public const string IMAGE = ManifestVerifier::COSIGN_IMAGE;

    /** Protects only the throwaway harness key. */
    public const string PASSWORD = 'fsa-test-harness';

    private function __construct(private string $keyDirectory) {}

    /** Generates `cosign.key` and `cosign.pub` in a new 0700 directory. */
    public static function generate(string $keyDirectory): self
    {
        if (! is_dir($keyDirectory) && ! mkdir($keyDirectory, 0700, true)) {
            throw new RuntimeException("Cannot create {$keyDirectory}.");
        }

        $cosign = new self($keyDirectory);
        $cosign->mustRun(['-v', "{$keyDirectory}:/keys", '-w', '/keys'], ['generate-key-pair']);

        return $cosign;
    }

    public function publicKey(): string
    {
        return "{$this->keyDirectory}/cosign.pub";
    }

    /** Signs a file with `sign-blob --bundle` and returns the bundle path (`<file>.sigstore.json`). */
    public function signBlob(string $file): string
    {
        $name = basename($file);
        $this->mustRun([...$this->keyMounts(true), '-v', dirname($file).':/work', '-w', '/work'], [
            'sign-blob', '--key', '/keys/cosign.key', '--signing-config', '/config/signing-config.json',
            '--bundle', "{$name}.sigstore.json", '--yes', $name,
        ]);

        return "{$file}.sigstore.json";
    }

    /**
     * Verifies a blob against the local public key with the pinned cosign image, the one `install.sh` and `fsa`
     * verify with (the app image ships no cosign, ADR 0071); returns the finished process (exit code 0 = verified).
     */
    public function verifyBlob(string $file, string $bundle): Process
    {
        return $this->run([
            ...$this->keyMounts(false), '-v', realpath($file).':/work/blob:ro', '-v', realpath($bundle).':/work/bundle:ro',
        ], ['verify-blob', '--key', '/keys/cosign.pub', '--bundle', '/work/bundle', '--insecure-ignore-tlog=true', '/work/blob']);
    }

    /** Signs an image and, recursively, every manifest of its index (KTD22), in a local registry. */
    public function signImage(string $reference): void
    {
        $this->mustRun([...$this->keyMounts(true), '--network', 'host'], [
            'sign', '--key', '/keys/cosign.key', '--signing-config', '/config/signing-config.json', '--recursive', '--yes', $reference,
        ]);
    }

    public function verifyImage(string $reference): Process
    {
        return $this->run([...$this->keyMounts(false), '--network', 'host'], [
            'verify', '--key', '/keys/cosign.pub', '--insecure-ignore-tlog=true', $reference,
        ]);
    }

    /**
     * @return list<string>
     */
    private function keyMounts(bool $private): array
    {
        $config = TestImage::repository().'/tests/Install/Harness/cosign-signing-config.json';

        return $private
            ? ['-v', "{$this->keyDirectory}:/keys:ro", '-v', "{$config}:/config/signing-config.json:ro"]
            : ['-v', "{$this->publicKey()}:/keys/cosign.pub:ro"];
    }

    /**
     * @param  list<string>  $options
     * @param  list<string>  $arguments
     */
    private function mustRun(array $options, array $arguments): void
    {
        $process = $this->run($options, $arguments);
        if (! $process->isSuccessful()) {
            throw new RuntimeException('cosign '.$arguments[0]." failed:\n".$process->getErrorOutput().$process->getOutput());
        }
    }

    /**
     * @param  list<string>  $options
     * @param  list<string>  $arguments
     */
    private function run(array $options, array $arguments): Process
    {
        $network = in_array('--network', $options, true) ? [] : ['--network', 'none'];
        $process = new Process([
            'docker', 'run', '--rm', ...$network, '--user', $this->user(), '-e', 'HOME=/tmp', '-e', 'COSIGN_PASSWORD='.self::PASSWORD,
            ...$options, self::IMAGE, ...$arguments,
        ]);
        $process->setTimeout(300);
        $process->run();

        return $process;
    }

    private function user(): string
    {
        return function_exists('posix_getuid') && function_exists('posix_getgid')
            ? posix_getuid().':'.posix_getgid()
            : getmyuid().':'.getmygid();
    }
}
