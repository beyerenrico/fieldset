<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use RuntimeException;
use SensitiveParameter;

/**
 * age 1.3 (KTD17, ADR 0059, ADR 0084), run without a shell. Backups are encrypted to X25519 recipients (public keys
 * only). The owner's identity file is itself encrypted with a passphrase through `age-plugin-batchpass`, which reads it
 * from file descriptor 3 (`AGE_PASSPHRASE_FD=3`; verified against `age-plugin-batchpass --help` of the image): no
 * terminal, no environment variable and no argument ever carries the passphrase. Only small payloads pass through
 * PHP (identities, the decrypt test); a backup set goes from tar straight into age through a pipe.
 */
class AgeCli
{
    public function __construct(
        private readonly string $age = 'age',
        private readonly string $keygen = 'age-keygen',
        private readonly string $tar = 'tar',
    ) {}

    /** @return array{identity: string, recipient: string} a new X25519 key pair */
    public function generateIdentity(): array
    {
        [$code, $stdout, $stderr] = $this->exec([$this->keygen]);
        $recipient = preg_match('/^# public key: (age1[0-9a-z]+)$/m', $stdout, $match) === 1 ? $match[1] : null;
        $identity = preg_match('/^(AGE-SECRET-KEY-1[0-9A-Z]+)$/m', $stdout, $secret) === 1 ? $secret[1] : null;
        if ($code !== 0 || $recipient === null || $identity === null) {
            throw new RuntimeException('age-keygen failed: '.$this->tail($stderr));
        }

        return ['identity' => $identity."\n", 'recipient' => $recipient];
    }

    /** Writes `$plaintext` encrypted with the passphrase (scrypt, through batchpass) to `$output`. */
    public function encryptWithPassphrase(#[SensitiveParameter] string $plaintext, #[SensitiveParameter] string $passphrase, string $output): void
    {
        [$code, , $stderr] = $this->exec([$this->age, '--encrypt', '-j', 'batchpass', '--output', $output], $plaintext, $passphrase);
        if ($code !== 0) {
            throw new RuntimeException('age could not encrypt with the passphrase: '.$this->tail($stderr));
        }
    }

    /** The content of a passphrase-encrypted file; a wrong passphrase throws and returns nothing. */
    public function decryptWithPassphrase(string $file, #[SensitiveParameter] string $passphrase): string
    {
        [$code, $stdout, $stderr] = $this->exec([$this->age, '--decrypt', '-j', 'batchpass', $file], null, $passphrase);
        if ($code !== 0) {
            throw new RuntimeException('age could not decrypt '.basename($file).': '.$this->tail($stderr));
        }

        return $stdout;
    }

    /** @param  list<string>  $recipients */
    public function encryptToRecipients(#[SensitiveParameter] string $plaintext, array $recipients, string $output): void
    {
        [$code, , $stderr] = $this->exec([$this->age, '--encrypt', ...$this->recipientFlags($recipients), '--output', $output], $plaintext);
        if ($code !== 0) {
            throw new RuntimeException('age could not encrypt: '.$this->tail($stderr));
        }
    }

    /** Decrypts a small file with an identity given on stdin (`-i -`). */
    public function decryptWithIdentity(#[SensitiveParameter] string $identity, string $file): string
    {
        [$code, $stdout, $stderr] = $this->exec([$this->age, '--decrypt', '--identity', '-', $file], $identity);
        if ($code !== 0) {
            throw new RuntimeException('age could not decrypt '.basename($file).': '.$this->tail($stderr));
        }

        return $stdout;
    }

    /**
     * The files of `$directory` as one tar stream piped into age, encrypted to every recipient, written to `$output`
     * (KTD16: "an encrypted backup is one age file of that set").
     *
     * @param  list<string>  $files
     * @param  list<string>  $recipients
     */
    public function encryptDirectory(string $directory, array $files, array $recipients, string $output): void
    {
        $tar = @proc_open([$this->tar, '-C', $directory, '--numeric-owner', '-cf', '-', ...$files], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tarPipes);
        if (! is_resource($tar)) {
            throw new RuntimeException('tar could not start.');
        }
        $age = @proc_open([$this->age, '--encrypt', ...$this->recipientFlags($recipients), '--output', $output], [0 => $tarPipes[1], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $agePipes);
        fclose($tarPipes[1]);
        if (! is_resource($age)) {
            proc_close($tar);
            throw new RuntimeException('age could not start.');
        }
        $ageError = (string) stream_get_contents($agePipes[2]);
        fclose($agePipes[1]);
        fclose($agePipes[2]);
        $ageCode = proc_close($age);
        $tarError = (string) stream_get_contents($tarPipes[2]);
        fclose($tarPipes[2]);
        $tarCode = proc_close($tar);
        if ($tarCode !== 0 || $ageCode !== 0) {
            throw new RuntimeException('Encrypting the backup failed: '.$this->tail($tarCode !== 0 ? $tarError : $ageError));
        }
    }

    /**
     * Runs a program with optional stdin and an optional passphrase on fd 3 (AGE_PASSPHRASE_FD=3).
     *
     * @param  list<string>  $command
     * @return array{0: int, 1: string, 2: string}
     */
    protected function exec(array $command, #[SensitiveParameter] ?string $stdin = null, #[SensitiveParameter] ?string $passphrase = null): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], ...($passphrase === null ? [] : [3 => ['pipe', 'r']])];
        $env = getenv();
        if ($passphrase !== null) {
            $env['AGE_PASSPHRASE_FD'] = '3';
        }
        unset($env['AGE_PASSPHRASE']); // only fd 3 may carry a passphrase
        $process = @proc_open($command, $descriptors, $pipes, null, $env);
        if (! is_resource($process)) {
            throw new RuntimeException("{$command[0]} could not start.");
        }
        if ($passphrase !== null) {
            fwrite($pipes[3], $passphrase);
            fclose($pipes[3]);
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * @param  list<string>  $recipients
     * @return list<string>
     */
    private function recipientFlags(array $recipients): array
    {
        if ($recipients === []) {
            throw new RuntimeException('No age recipient to encrypt to.');
        }

        return array_merge(...array_map(static fn (string $recipient): array => ['--recipient', $recipient], $recipients));
    }

    private function tail(string $stderr): string
    {
        $lines = array_values(array_filter(explode("\n", trim($stderr))));

        return $lines === [] ? 'no message' : implode(' ', array_slice($lines, -2));
    }
}
