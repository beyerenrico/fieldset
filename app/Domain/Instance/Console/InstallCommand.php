<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Install\Installer;
use App\Domain\Instance\Install\InstallRequest;
use App\Domain\Instance\Install\InstallSituation;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fsa install` (§7, F2, R9–R15, R17, R26, ADR 0072, ADR 0076): creates and starts a local- or proxy-mode instance
 * from the release install.sh verified, resumes a half-finished install, and changes nothing on a finished one. A
 * new install needs --org and --owner-email: prompted in a terminal, exit 2 (`input_required`) without one or
 * under --yes/--json; exit 6 stays reserved for confirmations (§7). Flags of later pre-releases are recognised and
 * refused with exit 2; --domain runs the DNS preflight first (AE5).
 */
#[Description('Create and start an instance (local or proxy mode), or resume an unfinished install')]
#[Signature('fsa:install
        {--org= : Organization name (required for a new install)}
        {--owner-email= : Owner e-mail (required for a new install); the owner gets a one-time sign-in link}
        {--owner-name= : Owner name (default: the part of the e-mail before the @)}
        {--locale= : Organization and owner locale: de or en (default de)}
        {--port= : Loopback port of the app (default 8080, or the next free one; local mode and --behind-proxy port)}
        {--release= : Release version; the fsa wrapper passes --version as this. Must match the verified manifest}
        {--domain= : Domain mode (2.0.0-alpha.2): runs the DNS preflight, then stops}
        {--behind-proxy= : Proxy mode: traefik or caddy (labels on a shared Docker network) or port (a loopback port)}
        {--url= : Public URL your proxy serves in proxy mode, e.g. https://forms.example.com}
        {--proxy-network= : Docker network shared with the proxy (--behind-proxy traefik or caddy); only the app joins it}
        {--trusted-proxy= : The proxy container (name or ID) or its address on that network; default: found by its image}
        {--phone-https= : Phone HTTPS via local-ca or tunnel (2.0.0-beta.1)}
        {--demo : Demo organization and users (2.0.0-beta.1)}
        {--encrypt : Encrypted backups (2.0.0-rc.1)}
        {--from-backup= : Create the instance from a backup (2.0.0-rc.1)}
        {--open : Open the owner sign-in link in the browser of a desktop session instead of printing it}')]
final class InstallCommand extends FsaCommand
{
    /** Option => the pre-release that brings it. */
    private const array LATER = [
        'phone-https' => '2.0.0-beta.1',
        'demo' => '2.0.0-beta.1', 'encrypt' => '2.0.0-rc.1', 'from-backup' => '2.0.0-rc.1',
    ];

    private const array PROMPTS = ['organization' => 'Organization name', 'owner_email' => 'Owner e-mail'];

    protected function opensBrowser(): bool
    {
        return (bool) $this->option('open');
    }

    protected function perform(FsaContext $context): CommandResult
    {
        $this->refuseLaterOptions();
        $request = InstallRequest::fromOptions($this->options());
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
