<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Config\Appliers\OperatorEmailApplier;
use App\Domain\Instance\Config\ConfigKeys;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\ConfigSetter;
use App\Domain\Instance\Config\ConfigShow;
use App\Domain\Instance\Config\SmtpCredentials;
use App\Domain\Instance\Install\AcmeCaRoot;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\Tunnel\TunnelSweeper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Symfony\Component\Console\Input\InputInterface;

/**
 * `fieldset config show` and `fieldset config set <key> <value>` (§7, R31, R32, R54, ADR 0077). `show` prints the effective
 * settings (secrets redacted) and what each opt-in feature contacts; `--catcher-login` adds the catcher UI's password to
 * `secrets` (or --secrets-file) in the loopback modes (ADR 0080). `set` changes one setting under the instance lock and
 * journal: validate (exit 2), confirm a URL change (exit 6 without --yes or a terminal), preflight (exit 3), apply,
 * verify, and revert on failure (exit 5 `rolled_back`, `reverted: true`, ADR 0078; exit 8 when the revert fails too
 * and the instance is stopped). The same value again is `changed: false` without touching Docker.
 *
 * Keys (ConfigKeys): domain, url, port, trusted-proxy, mode, acme-email, acme-ca, mail, phone-https, lan-address and
 * operator-email. `config set mail smtp|capture` takes the SMTP flags (SmtpInput, shared with `fieldset install --smtp-*`);
 * the password comes only from stdin (`--password-stdin`, or a hidden prompt in a terminal) or a file
 * (`--password-file`), never from a flag value (exit 2, SecretFlagGuard). `config set phone-https local-ca|off` and
 * `config set lan-address <IPv4>` run the local CA of local mode (ADR 0082; the quick tunnel is `fieldset tunnel`).
 * `config set operator-email <email>` grants the operator role app-side (OperatorEmailApplier, ADR 0080).
 * `config set webhooks on` confirms a move (`fieldset install --from-backup` pauses webhooks; ADR 0085).
 * `config set backup-schedule <HH:MM|off>`, `backup-retention <n>` and `backup-encryption on|off` set the backup policy
 * (ADR 0084); `backup-encryption on` writes a passphrase-protected identity to `--identity-out`, the passphrase from
 * `--passphrase-file` or `--passphrase-stdin` (PassphraseInput), never from a flag value.
 */
#[Description('Show the settings (secrets redacted) or change one: fieldset config show | fieldset config set <key> <value>')]
#[Signature('fieldset:config
        {action : show, or set}
        {key? : For set: domain, url, port, trusted-proxy, mode, acme-email, acme-ca, mail, phone-https, lan-address, operator-email, backup-schedule, backup-retention, backup-encryption, webhooks or update-check}
        {value? : For set: the new value}
        {--acme-email= : config set domain: contact address for the ACME account}
        {--acme-staging : config set domain: Let\'s Encrypt staging (untrusted certificates; for trials)}
        {--acme-ca= : config set domain: another ACME directory URL}
        {--acme-ca-root-file= : config set domain or acme-ca: PEM root of a private ACME directory\'s HTTPS certificate}
        {--smtp-host= : config set mail smtp: the SMTP server}
        {--smtp-port= : config set mail smtp: its port (default 587; 465 with --smtp-encryption tls, 25 with none)}
        {--smtp-encryption= : config set mail smtp: starttls (required, the default), tls (implicit TLS) or none (a relay without TLS)}
        {--smtp-username= : config set mail smtp: the SMTP account; its password from --password-stdin or --password-file}
        {--password-stdin : config set mail smtp: read the SMTP password from stdin (a hidden prompt in a terminal)}
        {--password-file= : config set mail smtp: read the SMTP password from this file (mode 0600)}
        {--from-address= : config set mail smtp: the sender address}
        {--from-name= : config set mail smtp: the sender name}
        {--test-to= : config set mail smtp: recipient of the test mail (default: the longest-standing operator)}
        {--remove-catcher : config set mail smtp: remove the mail catcher and the mail it captured}
        {--revoke-previous : config set operator-email: revoke the operator role of everyone else}
        {--catcher-login : config show: add the mail catcher UI\'s password to secrets (local and proxy mode)}
        {--identity-out= : config set backup-encryption on: write the passphrase-protected backup identity here (outside the instance directory)}
        {--passphrase-file= : config set backup-encryption on: read the identity\'s passphrase from this file}
        {--passphrase-stdin : config set backup-encryption on: read the identity\'s passphrase from stdin (a hidden prompt, twice, in a terminal)}')]
final class ConfigCommand extends FieldsetCommand
{
    /** Container key of a closure that returns stdin (tests); the real stdin otherwise (SmtpInput). */
    public const string STDIN = SmtpInput::STDIN;

    /** `config set` changes the instance and saves the measured owner with it (2.1.1 U4, KTD6); `config show` only reads. */
    protected function recordsOwner(): bool
    {
        return $this->input instanceof InputInterface && $this->input->hasArgument('action') && $this->input->getArgument('action') === 'set';
    }

    protected function perform(FieldsetContext $context): CommandResult
    {
        return match ($this->argument('action')) {
            'show' => $this->show($context),
            'set' => $this->set($context),
            default => throw InvalidInput::usage('Use `fieldset config show` or `fieldset config set <key> <value>`.'),
        };
    }

    private function show(FieldsetContext $context): CommandResult
    {
        if ($this->argument('key') !== null || $this->argument('value') !== null) {
            throw InvalidInput::usage('`fieldset config show` takes no further arguments.');
        }
        $show = $this->laravel->make(ConfigShow::class);

        return $this->option('catcher-login') === true ? $show->withCatcherLogin($show->build(), $context) : $show->build();
    }

    private function set(FieldsetContext $context): CommandResult
    {
        $key = $this->argument('key');
        $value = $this->argument('value');
        if (is_string($key) && trim($key) !== '') {
            ConfigKeys::check(strtolower(trim($key))); // a later or unknown key says so before a value is asked for
        }
        if (! is_string($key) || $key === '' || ! is_string($value) || trim($value) === '') {
            throw InvalidInput::usage('Use `fieldset config set <key> <value>`; `fieldset config show` lists the keys.');
        }
        if ($this->option('catcher-login') === true) {
            throw new InvalidInput('--catcher-login belongs to `fieldset config show`.', 'invalid_flag');
        }
        $confirm = $this->canPrompt($context, ignoreYes: true) ? fn (string $question): bool => $this->confirm($question) : null;
        $request = $this->request(strtolower(trim($key)), $value, $context);
        // An expired or stopped quick tunnel goes first (AE6, ADR 0082): every key is a mutating command.
        $swept = $this->laravel->make(TunnelSweeper::class)->sweep($context);
        if ($request->key === OperatorEmailApplier::KEY) {
            $secretsFile = is_string($this->option('secrets-file')) && $this->option('secrets-file') !== '';

            return TunnelSweeper::then($this->resultName(), $swept, fn (): CommandResult => $this->laravel->make(OperatorEmailApplier::class)->set($request, $context, $confirm, $secretsFile));
        }

        return TunnelSweeper::then($this->resultName(), $swept, fn (): CommandResult => $this->laravel->make(ConfigSetter::class)->set($request, $context, $confirm));
    }

    private function request(string $key, string $value, FieldsetContext $context): ConfigRequest
    {
        $email = $this->option('acme-email');
        $ca = $this->option('acme-ca');
        $root = $this->option('acme-ca-root-file');
        $ca = is_string($ca) && $ca !== '' ? $ca : null;
        $smtp = SmtpInput::fromCommand($this, $context);
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
            $smtp->mailOptions(),
            $this->credentials($key, $smtp, $context),
            $this->option('revoke-previous') === true,
            $this->identityOut(),
            PassphraseInput::fromCommand($this, $context)->read($context),
        );
    }

    private function identityOut(): ?string
    {
        $path = $this->option('identity-out');

        return is_string($path) && trim($path) !== '' ? trim($path) : null;
    }

    /** The SMTP user name and the password from stdin or a file (SmtpInput); read only for `config set mail`. */
    private function credentials(string $key, SmtpInput $smtp, FieldsetContext $context): ?SmtpCredentials
    {
        if ($key !== 'mail' && $smtp->passwordSource() !== null) {
            throw new InvalidInput("The SMTP options do not apply to `config set {$key}`.", 'invalid_flag', '--password-stdin and --password-file belong to `config set mail smtp`.');
        }

        return $smtp->credentials($context);
    }
}
