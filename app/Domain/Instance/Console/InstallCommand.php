<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Install\AcmeCaRoot;
use App\Domain\Instance\Install\Installer;
use App\Domain\Instance\Install\InstallRequest;
use App\Domain\Instance\Install\InstallSituation;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fsa install` (§7, F1, F2, R9–R17, R26, ADR 0072, ADR 0075, ADR 0076): creates and starts an instance from the
 * release install.sh verified, in local mode, in domain mode (--domain: the edge with automatic HTTPS, after the DNS
 * preflight, AE5) or in proxy mode (--behind-proxy); resumes a half-finished install, and changes nothing on a
 * finished one. A new install needs --org and --owner-email: prompted in a terminal, exit 2 (`input_required`)
 * without one or under --yes/--json; exit 6 stays reserved for confirmations (§7). --demo adds the demo data after the
 * stack started (ADR 0081). Flags of later pre-releases are recognised and refused with exit 2.
 */
#[Description('Create and start an instance (local, domain or proxy mode), or resume an unfinished install')]
#[Signature('fsa:install
        {--org= : Organization name (required for a new install)}
        {--owner-email= : Owner e-mail (required for a new install); the owner gets a one-time sign-in link}
        {--owner-name= : Owner name (default: the part of the e-mail before the @)}
        {--locale= : Organization and owner locale: de or en (default de)}
        {--port= : Loopback port of the app (default 8080, or the next free one; local mode and --behind-proxy port)}
        {--release= : Release version; the fsa wrapper passes --version as this. Must match the verified manifest}
        {--domain= : Domain mode: the edge serves https://<domain> with a certificate from ACME (DNS must point here)}
        {--acme-email= : Domain mode: contact address for the ACME account (shared with the CA; default none)}
        {--acme-staging : Domain mode: Let\'s Encrypt staging (untrusted certificates; for trial installs)}
        {--acme-ca= : Domain mode: another ACME directory URL (default Let\'s Encrypt production)}
        {--acme-ca-root-file= : Domain mode: PEM root of a private ACME directory\'s HTTPS certificate}
        {--behind-proxy= : Proxy mode: traefik or caddy (labels on a shared Docker network) or port (a loopback port)}
        {--url= : Public URL your proxy serves in proxy mode, e.g. https://forms.example.com}
        {--proxy-network= : Docker network shared with the proxy (--behind-proxy traefik or caddy); only the app joins it}
        {--trusted-proxy= : The proxy container (name or ID) or its address on that network; default: found by its image}
        {--phone-https= : Phone HTTPS via local-ca or tunnel (2.0.0-beta.1)}
        {--demo : Add the demo organization, forms, workflow and users (random passwords, listed once, unless loopback-only)}
        {--encrypt : Encrypted backups (2.0.0-rc.1)}
        {--from-backup= : Create the instance from a backup (2.0.0-rc.1)}
        {--open : Open the owner sign-in link in the browser of a desktop session instead of printing it}')]
final class InstallCommand extends FsaCommand
{
    /** Option => the pre-release that brings it. */
    private const array LATER = [
        'phone-https' => '2.0.0-beta.1',
        'encrypt' => '2.0.0-rc.1', 'from-backup' => '2.0.0-rc.1',
    ];

    private const array PROMPTS = ['organization' => 'Organization name', 'owner_email' => 'Owner e-mail'];

    protected function opensBrowser(): bool
    {
        return (bool) $this->option('open');
    }

    protected function perform(FsaContext $context): CommandResult
    {
        $this->refuseLaterOptions();
        $request = $this->withAcmeOptions(InstallRequest::fromOptions($this->options()));
        $request = $this->option('demo') === true ? $request->withDemo() : $request;
        $installer = $this->laravel->make(Installer::class);
        if ($installer->situation() === InstallSituation::Fresh) {
            $request = $this->askMissing($request, $context);
        }

        return $installer->install($request, $context, is_string($this->option('secrets-file')) && $this->option('secrets-file') !== '');
    }

    private function refuseLaterOptions(): void
    {
        foreach (self::LATER as $option => $release) {
            $value = $this->option($option);
            if (! in_array($value, [null, false, ''], true)) {
                throw new InvalidInput("--{$option} is not available in this version; it arrives with {$release}.", 'unsupported_option', 'Run fsa install without it.');
            }
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
    private function askMissing(InstallRequest $request, FsaContext $context): InstallRequest
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
                'Pass '.implode(' and ', $flags).' (fsa never prompts without a terminal, or with --yes or --json).',
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
