<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\Demo\DemoPasswords;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\Tunnel\ExposureCheck;

/**
 * The demo-password rule of `fieldset config set` (R29, AE2, E9, ADR 0081 amendment 2026-10-19), run by ConfigSetter in the
 * preflight, under the lock and before anything changes. A change widens the instance's reach when the target state
 * has a reason DemoPasswords::exposure() does not find in the current one: local → domain mode (`config set domain`),
 * proxy → domain mode, the local CA (`config set phone-https local-ca`). Such a change is refused (exit 3
 * `default_password_in_use`) while any account still signs in with the documented demo password `password`:
 *
 * - other accounts first: fieldset never changes a real user's password (ExposureCheck::refuseOtherDefaults());
 * - demo accounts: `config set` cannot hand out new passwords, so they are rotated first with
 *   `fieldset demo --rotate-passwords`, which lists the new ones once.
 *
 * A change that widens nothing (another domain, back to local mode, a port) runs no check. `fieldset tunnel start` keeps
 * its own rule: it rotates the demo accounts itself (QUESTIONS 39).
 */
final readonly class DefaultPasswordGuard
{
    /** How each new reason is named in the refusal, and where it makes the accounts reachable. */
    private const array REACH = [
        'domain_mode' => ['domain mode', 'from the internet'],
        'proxy_mode' => ['proxy mode', 'through the proxy'],
        'local_ca' => ['the local CA', 'from your network'],
        'tunnel' => ['the quick tunnel', 'from the internet'],
    ];

    public function __construct(private InstanceDirectory $directory, private AppCommands $app) {}

    /** @return list<string> the reasons the target adds to the current state's (empty: the change widens nothing) */
    private function widening(InstanceState $before, InstanceState $after): array
    {
        $current = DemoPasswords::exposure($before, $this->directory);

        return array_values(array_diff(DemoPasswords::exposure($after, $this->directory), $current));
    }

    /**
     * @throws InstanceException exit 3 while an account signs in with `password`; exit 4 when the check fails
     */
    public function check(InstanceState $before, InstanceState $after): void
    {
        $reasons = $this->widening($before, $after);
        if ($reasons === []) {
            return;
        }
        [$what, $where] = self::REACH[$reasons[0]] ?? ['this change', 'beyond this machine'];
        $exposure = ExposureCheck::run($this->app, ComposeProject::forInstance($before, $this->directory));
        $exposure->refuseOtherDefaults($what);
        if ($exposure->demoDefaults === []) {
            return;
        }

        throw new InstanceException(
            count($exposure->demoDefaults)." demo account(s) still sign in with the password \"password\": {$what} would make them reachable {$where}.",
            ExitCode::PreflightFailed,
            'default_password_in_use',
            'Rotate them first with `fieldset demo --rotate-passwords` (it lists the new passwords once), then run the command again; nothing was changed.',
            false,
            ['users' => $exposure->demoDefaults, 'exposure' => $reasons],
        );
    }
}
