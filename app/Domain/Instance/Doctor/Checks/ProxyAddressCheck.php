<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Install\ProxySetup;
use App\Domain\Instance\State\InstanceState;

/**
 * Proxy mode (R17, KTD10, ADR 0076, ADR 0078): the app trusts exactly one proxy address, resolved at install. A
 * proxy recreated without a fixed address gets a new one, and from then on the app ignores its forwarded headers
 * (client addresses collapse to the proxy's). The check re-resolves the recorded container on the shared network (the
 * gateway in the `port` setup) and warns `proxy_address_changed` when the trusted address no longer matches.
 */
final readonly class ProxyAddressCheck implements DoctorCheck
{
    public const string WARNING = 'proxy_address_changed';

    public function key(): string
    {
        return 'proxy';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $state = $context->state;
        $drift = $state instanceof InstanceState && $context->installed()
            ? new ProxySetup($context->docker)->drift($state, ComposeProject::forInstance($state, $context->directory))
            : null;
        if ($drift === null) {
            return CheckResult::skipped($this->key(), 'Not in proxy mode.');
        }
        $trusted = implode(', ', $drift['trusted']);
        $details = ['setup' => $drift['setup'], 'network' => $drift['network'], 'container' => $drift['container'], 'trusted' => $drift['trusted'], 'current' => $drift['current']];
        if (! $drift['moved']) {
            return CheckResult::ok($this->key(), "The app trusts {$trusted}, where the proxy is.", $details);
        }
        if ($drift['setup'] === 'port' && $drift['current'] === null) {
            return CheckResult::skipped($this->key(), 'The instance network does not exist yet (not started).', $details);
        }
        $where = match (true) {
            $drift['setup'] === 'port' => "the instance network's gateway is now {$drift['current']}",
            $drift['current'] === null => "{$drift['container']} is not attached to {$drift['network']} any more",
            default => "{$drift['container']} now has {$drift['current']} on {$drift['network']}",
        };

        return CheckResult::warn(
            $this->key(),
            "The trusted proxy address no longer matches: the app trusts {$trusted}, but {$where}. Until it is updated, the app ignores the proxy's forwarded headers and sees every client as the proxy.",
            'Run `fsa config set trusted-proxy auto` (start the proxy first if it is down); give the proxy a fixed address on the network to avoid this.',
            $details,
            self::WARNING,
        );
    }
}
