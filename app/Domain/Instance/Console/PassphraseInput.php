<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Output\InvalidInput;
use Closure;
use Illuminate\Console\Command;
use SensitiveParameter;

/**
 * The passphrase that protects the backup identity (R40, R50, KTD17, ADR 0084): from `--passphrase-file` (a file that
 * holds only the passphrase, mounted read-only by the shim) or `--passphrase-stdin` (stdin; in a terminal a hidden
 * prompt, entered twice). Never a flag value (SecretFlagGuard), never journaled; registered with the redactor as soon
 * as it is read. Trailing line breaks are dropped, as age-plugin-batchpass does with its file descriptor. Empty input
 * (an empty file, nothing on stdin: install.sh never passes its stdin on) is `passphrase_missing`, not a weak passphrase
 * (M12 gate O7). `file()` and `stdin()` name the source for the journal, so a resume command repeats it (ResumeCommand).
 */
final readonly class PassphraseInput
{
    /** Container key of a closure that returns stdin (tests). */
    public const string STDIN = SmtpInput::STDIN;

    public const int MIN_LENGTH = 12;

    public const int MAX_BYTES = 4096;

    /**
     * @param  Closure(): string  $read
     * @param  (Closure(): string)|null  $confirm  the second hidden entry in a terminal
     */
    private function __construct(private ?string $file, private bool $stdin, private Closure $read, private ?Closure $confirm) {}

    /** @param  bool  $twice  a new passphrase is entered twice in a terminal; an existing one (restore, R40) once */
    public static function fromCommand(Command $command, FsaContext $context, bool $twice = true): self
    {
        $laravel = $command->getLaravel();
        $reader = $laravel->bound(self::STDIN) ? $laravel->make(self::STDIN) : null;
        $terminal = ! is_callable($reader) && ! $context->json && function_exists('posix_isatty') && posix_isatty(STDIN);
        $read = static function () use ($reader, $command, $terminal): string {
            $content = match (true) {
                is_callable($reader) => $reader(),
                $terminal => $command->secret('Passphrase for the backup identity'),
                default => stream_get_contents(STDIN, self::MAX_BYTES + 1),
            };

            return is_string($content) ? $content : '';
        };
        $confirm = $terminal && $twice ? static fn (): string => (string) $command->secret('The same passphrase again') : null;
        $file = $command->option('passphrase-file');

        return new self(is_string($file) && trim($file) !== '' ? trim($file) : null, $command->option('passphrase-stdin') === true, $read, $confirm);
    }

    public function given(): bool
    {
        return $this->file !== null || $this->stdin;
    }

    /** The absolute path of `--passphrase-file` (the journal records it; never the passphrase). */
    public function file(): ?string
    {
        return $this->file === null ? null : (str_starts_with($this->file, '/') ? $this->file : getcwd().'/'.$this->file);
    }

    public function stdin(): bool
    {
        return $this->file === null && $this->stdin;
    }

    /** @throws InvalidInput exit 2: both sources, an unreadable file, a passphrase that is too short or does not repeat */
    public function read(FsaContext $context): ?string
    {
        if ($this->file !== null && $this->stdin) {
            throw new InvalidInput('--passphrase-file and --passphrase-stdin exclude each other.', 'invalid_flag', 'Pass the passphrase one way.');
        }
        if (! $this->given()) {
            return null;
        }
        $passphrase = $this->clean($this->file !== null ? $this->readFile() : ($this->read)());
        if ($this->confirm instanceof Closure && ! hash_equals($passphrase, $this->clean(($this->confirm)()))) {
            throw new InvalidInput('The two passphrases differ.', 'passphrase_mismatch', 'Run the command again and enter the same passphrase twice.');
        }
        $context->redactor->register($passphrase);

        return $passphrase;
    }

    private function readFile(): string
    {
        $path = (string) $this->file();
        $content = is_file($path) && is_readable($path) ? file_get_contents($path, length: self::MAX_BYTES + 1) : false;
        if ($content === false) {
            throw new InvalidInput('The file of --passphrase-file cannot be read.', 'invalid_flag', 'Pass a readable file that holds only the passphrase.');
        }

        return $content;
    }

    private function clean(#[SensitiveParameter] string $content): string
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new InvalidInput('The passphrase is longer than 4096 bytes.', 'invalid_value', 'Pass the passphrase alone.');
        }
        $passphrase = (string) preg_replace('/[\r\n]+\z/', '', $content);
        if ($passphrase === '') {
            throw new InvalidInput($this->file !== null ? 'The file of --passphrase-file is empty.' : 'No passphrase arrived on stdin.', 'passphrase_missing',
                'Pipe the passphrase into the command (`… --passphrase-stdin < file`; install.sh does not pass its stdin on) or pass --passphrase-file <file>.');
        }
        if (mb_strlen($passphrase) < self::MIN_LENGTH) {
            throw new InvalidInput('The passphrase must have at least '.self::MIN_LENGTH.' characters.', 'weak_passphrase', 'It protects the only key that decrypts the backups: choose a long one and keep it apart from the identity file.');
        }

        return $passphrase;
    }
}
