<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Config\ConfigRollout;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * Where a quick tunnel may start (R61, ADR 0057, ADR 0082): local mode only, and not next to the local CA. Domain and
 * proxy mode already serve HTTPS on a public name, and phone HTTPS options exclude each other (exit 2).
 */
final class TunnelRules
{
    /** Seconds the stack may take to change (workers' grace period, `up --wait`, verification). */
    public const int STACK_SECONDS = ConfigRollout::APPLY_SECONDS + ConfigRollout::WAIT_SECONDS;

    /** What a confirmation covers: organizations besides the demo become reachable from the internet (R65). */
    public static function exposureQuestion(int $organizations): string
    {
        return "This instance holds {$organizations} organization(s) besides the demo. The quick tunnel makes their sign-in, the Field App and the API reachable from the internet through Cloudflare for 4 hours. Start it?";
    }

    /** @throws InvalidInput exit 2 */
    public static function assertCanStart(InstanceState $state): void
    {
        $settings = InstanceSettings::of($state);
        if ($state->mode() !== 'local') {
            throw new InvalidInput("The quick tunnel belongs to local mode; this instance runs in {$state->mode()} mode (R61).", 'invalid_combination', 'Phones open '.$settings->instanceUrl().' over HTTPS already. A tunnel needs `fsa config set mode local` first.');
        }
        if ($settings->localCa()) {
            throw new InvalidInput('The quick tunnel and the local CA exclude each other (R61).', 'invalid_combination', 'Turn the local CA off first: `fsa config set phone-https off --yes`.');
        }
    }
}
