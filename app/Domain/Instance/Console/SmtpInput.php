<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Config\MailOptions;
use App\Domain\Instance\Config\SmtpCredentials;
use App\Domain\Instance\Output\InvalidInput;
use Closure;
use Illuminate\Console\Command;

/**
 * The SMTP flags of `fieldset config set mail smtp` and `fieldset install --smtp-*` as typed (R25, R50, ADR 0080): the options as
 * MailOptions, the account as SmtpCredentials. The password comes only from stdin (`--password-stdin`: a hidden prompt
 * in a terminal, else the piped input) or a file (`--password-file`), never from a flag value (SecretFlagGuard exits 2
 * before the input is parsed). It is registered with the output redactor as soon as it is read.
 *
 * install.sh never passes its own stdin on (with `curl … | sh` it is the script itself): under install.sh,
 * `--password-stdin` works only as the hidden prompt in a terminal, else it is exit 2 `password_stdin_unavailable`.
 */
final readonly class SmtpInput
{
    /** Container key of a closure that returns stdin (tests); the real stdin otherwise. */
    public const string STDIN = 'fieldset.stdin';

    /** Longest SMTP password read from stdin or a file (one more byte means it is too long). */
    public const int MAX_SECRET_BYTES = 4096;

    /** The option flags of MailOptions (the user name only counts as given; its value is a credential). */
    private const array MAIL_FLAGS = ['smtp-host', 'smtp-port', 'smtp-encryption', 'smtp-username', 'from-address', 'from-name', 'test-to'];

    /**
     * @param  array<string, mixed>  $options  the command's options by name
     * @param  Closure(): string  $stdin  reads the password from stdin (or the terminal)
     */
    public function __construct(private array $options, private Closure $stdin) {}

    public static function fromCommand(Command $command, FieldsetContext $context): self
    {
        $laravel = $command->getLaravel();
        $reader = $laravel->bound(self::STDIN) ? $laravel->make(self::STDIN) : null;
        $stdin = static function () use ($reader, $command, $context): string {
            if (is_callable($reader)) {
                $content = $reader();
            } elseif (! $context->json && function_exists('posix_isatty') && posix_isatty(STDIN)) {
                $content = $command->secret('SMTP password');
            } else {
                $content = stream_get_contents(STDIN, self::MAX_SECRET_BYTES + 1);
            }

            return is_string($content) ? $content : '';
        };

        return new self($command->options(), $stdin);
    }

    /** The trimmed value of a value flag, or null when it is absent or empty. */
    public function value(string $flag): ?string
    {
        $value = $this->options[$flag] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** The SMTP flags as typed, or null when none was given. */
    public function mailOptions(): ?MailOptions
    {
        $given = array_filter(self::MAIL_FLAGS, fn (string $flag): bool => $this->value($flag) !== null);
        $removeCatcher = ($this->options['remove-catcher'] ?? false) === true;
        if ($given === [] && ! $removeCatcher) {
            return null;
        }

        return new MailOptions($this->value('smtp-host'), $this->value('smtp-port'), $this->value('smtp-encryption'), $this->value('from-address'),
            $this->value('from-name'), $this->value('test-to'), $removeCatcher, $this->value('smtp-username') !== null, $this->value('password-file'));
    }

    public function usernameGiven(): bool
    {
        return $this->value('smtp-username') !== null;
    }

    /**
     * Where the password comes from: `stdin`, `file` or null (exit 2 when both are given).
     *
     * @throws InvalidInput
     */
    public function passwordSource(): ?string
    {
        $file = $this->value('password-file') !== null;
        $stdin = ($this->options['password-stdin'] ?? false) === true;
        if ($file && $stdin) {
            throw new InvalidInput('--password-stdin and --password-file exclude each other.', 'invalid_flag', 'Pass the SMTP password one way.');
        }

        return match (true) {
            $file => 'file',
            $stdin => 'stdin',
            default => null,
        };
    }

    /**
     * The SMTP user name and the password from stdin or a file as given (MailValues validates them); null when neither
     * was given. The password is registered with the output redactor at once.
     *
     * @throws InvalidInput
     */
    public function credentials(FieldsetContext $context): ?SmtpCredentials
    {
        $password = match ($this->passwordSource()) {
            'file' => $this->readFile((string) $this->value('password-file')),
            'stdin' => $this->readStdin($context),
            default => null,
        };
        if ($password !== null) {
            $context->redactor->register($password);
        }
        $username = $this->value('smtp-username');

        return $username === null && $password === null ? null : new SmtpCredentials($username, $password);
    }

    private function readFile(string $path): string
    {
        $path = str_starts_with($path, '/') ? $path : getcwd().'/'.$path;
        $content = is_file($path) && is_readable($path) ? file_get_contents($path, length: self::MAX_SECRET_BYTES + 1) : false;
        if ($content === false) {
            throw new InvalidInput('The file of --password-file cannot be read.', 'invalid_flag', 'Pass a readable file that holds only the password.');
        }

        return $this->passwordFrom($content);
    }

    private function readStdin(FieldsetContext $context): string
    {
        if ($context->facts->launcher === 'install.sh' && $context->facts->tty !== true) {
            throw new InvalidInput(
                'install.sh does not pass its stdin on (with `curl … | sh` it is the script itself), so --password-stdin cannot read the SMTP password here.',
                'password_stdin_unavailable',
                'Pass --password-file=<path> (a file that holds only the password, mode 0600), or run install.sh in a terminal to be asked for it.',
            );
        }

        return $this->passwordFrom(($this->stdin)());
    }

    /** One trailing line break is dropped (`echo` adds it); anything longer than the limit is refused. */
    private function passwordFrom(string $content): string
    {
        if (strlen($content) > self::MAX_SECRET_BYTES) {
            throw new InvalidInput('The SMTP password is longer than 4096 bytes.', 'invalid_value', 'Pass the password alone.');
        }
        $password = (string) preg_replace('/\r?\n\z/', '', $content);
        if ($password === '') {
            throw new InvalidInput('No SMTP password arrived.', 'password_required', 'Pipe it on stdin (`printf %s "$PASS" | fieldset … --password-stdin`) or pass --password-file=<path>.');
        }

        return $password;
    }
}
