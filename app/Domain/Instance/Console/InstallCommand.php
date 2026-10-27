<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Config\Appliers\MailValues;
use App\Domain\Instance\Config\SmtpCredentials;
use App\Domain\Instance\Install\AcmeCaRoot;
use App\Domain\Instance\Install\FromBackupInput;
use App\Domain\Instance\Install\FromBackupInstall;
use App\Domain\Instance\Install\FromBackupRequest;
use App\Domain\Instance\Install\Installer;
use App\Domain\Instance\Install\InstallMail;
use App\Domain\Instance\Install\InstallRequest;
use App\Domain\Instance\Install\InstallSituation;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\Tunnel\InstallTunnel;
use App\Domain\Instance\Tunnel\TunnelOperation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fieldset install` (§7, F1, F2, R9–R17, R26, ADR 0072, ADR 0075, ADR 0076): creates and starts an instance from the
 * release install.sh verified, in local mode, in domain mode (--domain: the edge with automatic HTTPS, after the DNS
 * preflight, AE5) or in proxy mode (--behind-proxy); resumes a half-finished install, and changes nothing on a
 * finished one. A new install needs --org and --owner-email: prompted in a terminal, exit 2 (`input_required`)
 * without one or under --yes/--json; exit 6 stays reserved for confirmations (§7). --demo adds the demo data after the
 * stack started (ADR 0081). --phone-https local-ca serves https://<LAN address> through the edge with the instance's
 * own CA; --phone-https tunnel starts the quick tunnel once the install finished (ADR 0082). --smtp-host and the other
 * SMTP flags install straight into SMTP mode, without the mail catcher (InstallMail, ADR 0080 amendment): the password
 * comes from --password-file or --password-stdin (SmtpInput), never from a flag value. --from-backup creates the instance
 * from a backup instead (FromBackupInstall, plan U17, ADR 0085).
 */
#[Description('Create and start an instance (local, domain or proxy mode), or resume an unfinished install')]
#[Signature('fieldset:install
        {--org= : Organization name (required for a new install)}
        {--owner-email= : Owner e-mail (required for a new install); the owner gets a one-time sign-in link}
        {--owner-name= : Owner name (default: the part of the e-mail before the @)}
        {--locale= : Organization and owner locale: de or en (default de)}
        {--port= : Loopback port of the app (default 8080, or the next free one; local mode and --behind-proxy port)}
        {--release= : Release version; the fieldset wrapper passes --version as this. Must match the verified manifest}
        {--domain= : Domain mode: the edge serves https://<domain> with a certificate from ACME (DNS must point here)}
        {--acme-email= : Domain mode: contact address for the ACME account (shared with the CA; default none)}
        {--acme-staging : Domain mode: Let\'s Encrypt staging (untrusted certificates; for trial installs)}
        {--acme-ca= : Domain mode: another ACME directory URL (default Let\'s Encrypt production)}
        {--acme-ca-root-file= : Domain mode: PEM root of a private ACME directory\'s HTTPS certificate}
        {--behind-proxy= : Proxy mode: traefik or caddy (labels on a shared Docker network) or port (a loopback port)}
        {--url= : Public URL your proxy serves in proxy mode, e.g. https://forms.example.com}
        {--proxy-network= : Docker network shared with the proxy (--behind-proxy traefik or caddy); only the app joins it}
        {--trusted-proxy= : The proxy container (name or ID) or its address on that network; default: found by its image}
        {--phone-https= : Local mode: HTTPS for phones with local-ca (the instance\'s own CA on the LAN address) or tunnel (a quick tunnel through Cloudflare, 4 hours)}
        {--lan-address= : --phone-https local-ca: the IPv4 LAN address to serve (default: the best address the host reports)}
        {--demo : Add the demo organization, forms, workflow and users (random passwords, listed once, unless loopback-only)}
        {--smtp-host= : Deliver mail through this SMTP server from the start instead of capturing it (no mail catcher)}
        {--smtp-port= : The SMTP server\'s port (default 587; 465 with --smtp-encryption tls, 25 with none)}
        {--smtp-encryption= : starttls (required, the default), tls (implicit TLS) or none (a relay without TLS)}
        {--smtp-username= : The SMTP account; its password from --password-file or --password-stdin}
        {--password-file= : Read the SMTP password from this file (mode 0600)}
        {--password-stdin : Read the SMTP password from stdin (a hidden prompt in a terminal; install.sh: terminal only)}
        {--from-address= : The sender address of instance mail (default forms@<domain>, else forms@localhost)}
        {--from-name= : The sender name of instance mail}
        {--encrypt : Encrypted backups: not at install yet; use fieldset config set backup-encryption on afterwards}
        {--from-backup= : Create the instance from a backup (its data, URL and settings; a 1.0 backup too): mail starts captured, webhooks paused}
        {--identity-file= : --from-backup: the passphrase-protected backup identity of an encrypted backup}
        {--passphrase-file= : --from-backup: read the identity\'s passphrase from this file}
        {--passphrase-stdin : --from-backup: read the identity\'s passphrase from stdin (a hidden prompt in a terminal)}
        {--app-key-file= : --from-backup: the APP key(s) the backup was made with (one per line, or the old env/app.env); authenticates it}
        {--accept-unauthenticated : --from-backup: install from an archive no key from outside it authenticates (a 1.0 backup)}
        {--keep-recipients : --from-backup: keep the authenticated backup\'s age recipients, so new backups are encrypted to them}
        {--open : Open the owner sign-in link in the browser of a desktop session instead of printing it}')]
final class InstallCommand extends FieldsetCommand
{
    private const array PROMPTS = ['organization' => 'Organization name', 'owner_email' => 'Owner e-mail'];

    /** It changes the instance, so it saves the owner the shim measured with it (2.1.1 U4, KTD6). */
    protected function recordsOwner(): bool
    {
        return true;
    }

    protected function opensBrowser(): bool
    {
        return (bool) $this->option('open');
    }

    protected function perform(FieldsetContext $context): CommandResult
    {
        $this->refuseLaterOptions();
        $request = $this->withAcmeOptions(InstallRequest::fromOptions($this->options()));
        $request = $this->option('demo') === true ? $request->withDemo() : $request;
        $fromBackup = FromBackupInput::fromCommand($this, $context);
        if ($fromBackup instanceof FromBackupRequest) {
            $toFile = is_string($this->option('secrets-file')) && $this->option('secrets-file') !== '';

            return $this->laravel->make(FromBackupInstall::class)->install($request, $fromBackup, $context, $toFile);
        }
        FromBackupInput::refuseStray($this);
        $smtp = SmtpInput::fromCommand($this, $context);
        InstallMail::assertFlags($request->explicit(), $smtp->usernameGiven(), $smtp->passwordSource());
        $installer = $this->laravel->make(Installer::class);
        if ($installer->situation() === InstallSituation::Fresh) {
            $request = $this->askMissing($request, $context);
        }
        $account = MailValues::credentials($smtp->usernameGiven(), $smtp->credentials($context));
        $request = $account instanceof SmtpCredentials ? $request->withSmtpCredentials($account) : $request;

        $toFile = is_string($this->option('secrets-file')) && $this->option('secrets-file') !== '';
        $tunnel = $installer->effective($request)->phoneHttps() === 'tunnel'; // also on a resume without the flag
        $result = $installer->install($request, $context, $toFile);

        return $tunnel && $result->exitCode === ExitCode::Success && $result->changed
            ? InstallTunnel::start($result, $this->laravel->make(TunnelOperation::class), $context, $toFile)
            : $result;
    }

    private function refuseLaterOptions(): void
    {
        if ($this->option('encrypt') === true) {
            // Backups and their encryption exist since U16 (ADR 0084), but not as an install step yet.
            throw new InvalidInput('--encrypt is not available at install yet.', 'unsupported_option', 'Install without it, then run `fieldset config set backup-encryption on --identity-out <path> --passphrase-file <file>` (or `fieldset backup --encrypt …`).');
        }
    }

    /** --acme-staging is --acme-ca with Let's Encrypt staging; --acme-ca-root-file is read and checked here. */
    private function withAcmeOptions(InstallRequest $request): InstallRequest
    {
        if ($this->option('acme-staging') === true) {
            if (is_string($this->option('acme-ca')) && $this->option('acme-ca') !== '') {
                throw new InvalidInput('--acme-staging and --acme-ca exclude each other.', 'invalid_flag', 'Pass one of them: --acme-staging selects Let\'s Encrypt staging.');
            }
            $request = $request->with('acme_ca', InstanceSettings::ACME_STAGING);
        }
        $root = $this->option('acme-ca-root-file');

        return is_string($root) && $root !== '' ? $request->withCaRoot(AcmeCaRoot::read($root)) : $request;
    }

    /** R26: missing values are prompted in a terminal and an input error (exit 2) without one, under --yes or --json. */
    private function askMissing(InstallRequest $request, FieldsetContext $context): InstallRequest
    {
        $missing = $request->missing();
        if ($missing === []) {
            return $request;
        }
        $flags = array_map(static fn (string $key): string => InstallRequest::FLAGS[$key], $missing);
        if (! $this->canPrompt($context)) {
            throw new InstanceException(
                'A new install needs '.implode(' and ', $flags).'.',
                ExitCode::InvalidInput,
                'input_required',
                'Pass '.implode(' and ', $flags).' (fieldset never prompts without a terminal, or with --yes or --json).',
                false,
                ['missing' => $flags],
            );
        }
        foreach ($missing as $key) {
            $request = $request->with($key, (string) $this->ask(self::PROMPTS[$key]));
        }

        return $request;
    }
}
