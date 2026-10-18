<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Config\Appliers\OperatorEmailApplier;
use App\Domain\Instance\Config\ConfigKeys;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\ConfigSetter;
use App\Domain\Instance\Config\ConfigShow;
use App\Domain\Instance\Config\MailOptions;
use App\Domain\Instance\Config\SmtpCredentials;
use App\Domain\Instance\Install\AcmeCaRoot;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fsa config show` and `fsa config set <key> <value>` (§7, R31, R32, R54, ADR 0077). `show` prints the effective
 * settings (secrets redacted) and what each opt-in feature contacts; `--catcher-login` adds the catcher UI's password to
 * `secrets` (or --secrets-file) in the loopback modes (ADR 0080). `set` changes one setting under the instance lock and
 * journal: validate (exit 2), confirm a URL change (exit 6 without --yes or a terminal), preflight (exit 3), apply,
 * verify, and revert on failure (exit 5 `rolled_back`, `reverted: true`, ADR 0078; exit 8 when the revert fails too
 * and the instance is stopped). The same value again is `changed: false` without touching Docker.
 *
 * `config set mail smtp|capture` takes the SMTP flags; the password comes only from stdin (`--password-stdin`, or a
 * hidden prompt in a terminal) or a file (`--password-file`), never from a flag value (exit 2, SecretFlagGuard).
 * `config set operator-email <email>` grants the operator role app-side (OperatorEmailApplier, ADR 0080).
 */
#[Description('Show the settings (secrets redacted) or change one: fsa config show | fsa config set <key> <value>')]
#[Signature('fsa:config
        {action : show, or set}
        {key? : For set: domain, url, port, trusted-proxy, mode, acme-email, acme-ca, mail or operator-email}
        {value? : For set: the new value}
        {--acme-email= : config set domain: contact address for the ACME account}
        {--acme-staging : config set domain: Let\'s Encrypt staging (untrusted certificates; for trials)}
        {--acme-ca= : config set domain: another ACME directory URL}
        {--acme-ca-root-file= : config set domain or acme-ca: PEM root of a private ACME directory\'s HTTPS certificate}
        {--smtp-host= : config set mail smtp: the SMTP server}
        {--smtp-port= : config set mail smtp: its port (default 587, or 465 with --smtp-encryption tls)}
        {--smtp-encryption= : config set mail smtp: starttls (when offered, the default) or tls (implicit TLS)}
        {--smtp-username= : config set mail smtp: the SMTP account; its password from --password-stdin or --password-file}
        {--password-stdin : config set mail smtp: read the SMTP password from stdin (a hidden prompt in a terminal)}
        {--password-file= : config set mail smtp: read the SMTP password from this file (mode 0600)}
        {--from-address= : config set mail smtp: the sender address}
        {--from-name= : config set mail smtp: the sender name}
        {--test-to= : config set mail smtp: recipient of the test mail (default: the longest-standing operator)}
        {--remove-catcher : config set mail smtp: remove the mail catcher and the mail it captured}
        {--revoke-previous : config set operator-email: revoke the operator role of everyone else}
        {--catcher-login : config show: add the mail catcher UI\'s password to secrets (local and proxy mode)}')]
final class ConfigCommand extends FsaCommand
{
    /** Container key of a closure that returns stdin (tests); the real stdin otherwise. */
    public const string STDIN = 'fsa.stdin';

    /** Longest SMTP password read from stdin or a file (one more byte means it is too long). */
    private const int MAX_SECRET_BYTES = 4096;

    private const array MAIL_FLAGS = ['smtp-host', 'smtp-port', 'smtp-encryption', 'smtp-username', 'from-address', 'from-name', 'test-to'];

    protected function perform(FsaContext $context): CommandResult
    {
        return match ($this->argument('action')) {
            'show' => $this->show($context),
            'set' => $this->set($context),
            default => throw InvalidInput::usage('Use `fsa config show` or `fsa config set <key> <value>`.'),
        };
    }

    private function show(FsaContext $context): CommandResult
    {
        if ($this->argument('key') !== null || $this->argument('value') !== null) {
            throw InvalidInput::usage('`fsa config show` takes no further arguments.');
        }
        $show = $this->laravel->make(ConfigShow::class);

        return $this->option('catcher-login') === true ? $show->withCatcherLogin($show->build(), $context) : $show->build();
    }

    private function set(FsaContext $context): CommandResult
    {
        $key = $this->argument('key');
        $value = $this->argument('value');
        if (is_string($key) && trim($key) !== '') {
            ConfigKeys::check(strtolower(trim($key))); // a later or unknown key says so before a value is asked for
        }
        if (! is_string($key) || $key === '' || ! is_string($value) || trim($value) === '') {
            throw InvalidInput::usage('Use `fsa config set <key> <value>`; `fsa config show` lists the keys.');
        }
        if ($this->option('catcher-login') === true) {
            throw new InvalidInput('--catcher-login belongs to `fsa config show`.', 'invalid_flag');
        }
        $confirm = $this->canPrompt($context, ignoreYes: true) ? fn (string $question): bool => $this->confirm($question) : null;
        $request = $this->request(strtolower(trim($key)), $value, $context);
        if ($request->key === OperatorEmailApplier::KEY) {
            return $this->laravel->make(OperatorEmailApplier::class)->set($request, $context, $confirm, is_string($this->option('secrets-file')) && $this->option('secrets-file') !== '');
        }

        return $this->laravel->make(ConfigSetter::class)->set($request, $context, $confirm);
    }

    private function request(string $key, string $value, FsaContext $context): ConfigRequest
    {
        $email = $this->option('acme-email');
        $ca = $this->option('acme-ca');
        $root = $this->option('acme-ca-root-file');
        $ca = is_string($ca) && $ca !== '' ? $ca : null;
        if ($this->option('acme-staging') === true) {
            if ($ca !== null) {
                throw new InvalidInput('--acme-staging and --acme-ca exclude each other.', 'invalid_flag', 'Pass one of them: --acme-staging selects Let\'s Encrypt staging.');
            }
            $ca = InstanceSettings::ACME_STAGING;
        }

        return new ConfigRequest(
            $key,
            $value,
            is_string($email) && $email !== '' ? $email : null,
            $ca,
            is_string($root) && $root !== '' ? AcmeCaRoot::read($root) : null,
            $this->mailOptions(),
            $this->credentials($key, $context),
            $this->option('revoke-previous') === true,
        );
    }

    /** The SMTP flags as typed, or null when none was given. */
    private function mailOptions(): ?MailOptions
    {
        $value = fn (string $flag): ?string => is_string($this->option($flag)) && trim($this->option($flag)) !== '' ? trim($this->option($flag)) : null;
        $given = array_filter(self::MAIL_FLAGS, static fn (string $flag): bool => $value($flag) !== null);
        if ($given === [] && $this->option('remove-catcher') !== true) {
            return null;
        }

        return new MailOptions($value('smtp-host'), $value('smtp-port'), $value('smtp-encryption'), $value('from-address'), $value('from-name'), $value('test-to'),
            $this->option('remove-catcher') === true, $value('smtp-username') !== null);
    }

    /** The SMTP user name and the password from stdin or a file; read only for `config set mail`. */
    private function credentials(string $key, FsaContext $context): ?SmtpCredentials
    {
        $username = $this->option('smtp-username');
        $username = is_string($username) && trim($username) !== '' ? trim($username) : null;
        $file = $this->option('password-file');
        $file = is_string($file) && $file !== '' ? $file : null;
        $stdin = $this->option('password-stdin') === true;
        if ($file !== null && $stdin) {
            throw new InvalidInput('--password-stdin and --password-file exclude each other.', 'invalid_flag', 'Pass the SMTP password one way.');
        }
        if ($key !== 'mail' && ($file !== null || $stdin)) {
            throw new InvalidInput("The SMTP options do not apply to `config set {$key}`.", 'invalid_flag', '--password-stdin and --password-file belong to `config set mail smtp`.');
        }
        $password = match (true) {
            $file !== null => $this->readFile($file),
            $stdin => $this->readStdin($context),
            default => null,
        };
        if ($password !== null) {
            $context->redactor->register($password);
        }

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

    private function readStdin(FsaContext $context): string
    {
        $reader = $this->laravel->bound(self::STDIN) ? $this->laravel->make(self::STDIN) : null;
        if (is_callable($reader)) {
            $content = $reader();
        } elseif (! $context->json && function_exists('posix_isatty') && posix_isatty(STDIN)) {
            $content = $this->secret('SMTP password');
        } else {
            $content = stream_get_contents(STDIN, self::MAX_SECRET_BYTES + 1);
        }

        return $this->passwordFrom(is_string($content) ? $content : '');
    }

    /** One trailing line break is dropped (`echo` adds it); anything longer than the limit is refused. */
    private function passwordFrom(string $content): string
    {
        if (strlen($content) > self::MAX_SECRET_BYTES) {
            throw new InvalidInput('The SMTP password is longer than 4096 bytes.', 'invalid_value', 'Pass the password alone.');
        }
        $password = (string) preg_replace('/\r?\n\z/', '', $content);
        if ($password === '') {
            throw new InvalidInput('No SMTP password arrived.', 'password_required', 'Pipe it on stdin (`printf %s "$PASS" | fsa config set mail smtp … --password-stdin`) or pass --password-file=<path>.');
        }

        return $password;
    }
}
