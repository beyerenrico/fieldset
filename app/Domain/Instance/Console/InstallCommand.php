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
 * `fsa install` (§7, F2, R9–R15, R26, ADR 0072): creates and starts a local-mode instance from the release
 * install.sh verified, resumes a half-finished install, and changes nothing on a finished one. A new install
 * needs --org and --owner-email: prompted in a terminal, exit 6 without one or under --yes/--json. Flags of later
 * pre-releases are recognised and refused with exit 2; --domain runs the DNS preflight first (AE5).
 */
#[Description('Create and start an instance (local mode), or resume an unfinished install')]
#[Signature('fsa:install
        {--org= : Organization name (required for a new install)}
        {--owner-email= : Owner e-mail (required for a new install); the owner gets a one-time sign-in link}
        {--owner-name= : Owner name (default: the part of the e-mail before the @)}
        {--locale= : Organization and owner locale: de or en (default de)}
        {--port= : Loopback port of the app (default 8080, or the next free one)}
        {--release= : Release version; the fsa wrapper passes --version as this. Must match the verified manifest}
        {--domain= : Domain mode (2.0.0-alpha.2): runs the DNS preflight, then stops}
        {--behind-proxy= : Proxy mode (2.0.0-alpha.2)}
        {--url= : Public URL behind a proxy (2.0.0-alpha.2)}
        {--phone-https= : Phone HTTPS via local-ca or tunnel (2.0.0-beta.1)}
        {--demo : Demo organization and users (2.0.0-beta.1)}
        {--encrypt : Encrypted backups (2.0.0-rc.1)}
        {--from-backup= : Create the instance from a backup (2.0.0-rc.1)}')]
final class InstallCommand extends FsaCommand
{
    /** Option => the pre-release that brings it. */
    private const array LATER = [
        'behind-proxy' => '2.0.0-alpha.2', 'url' => '2.0.0-alpha.2', 'phone-https' => '2.0.0-beta.1',
        'demo' => '2.0.0-beta.1', 'encrypt' => '2.0.0-rc.1', 'from-backup' => '2.0.0-rc.1',
    ];

    private const array PROMPTS = ['organization' => 'Organization name', 'owner_email' => 'Owner e-mail'];

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

    /** R26: missing values are prompted in a terminal and an error (exit 6) without one, under --yes or --json. */
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
                ExitCode::ConfirmationRequired,
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
