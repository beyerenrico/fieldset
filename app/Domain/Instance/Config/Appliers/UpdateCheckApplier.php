<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\SettingApplier;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Release\ManifestVerifier;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\Upgrade\ReleaseFeed;

/**
 * `fsa config set update-check on|off` (E15, R32, R48, plan U20, ADR 0061, ADR 0089). Off by default. `on` records the
 * newest release's manifest URL under the release URL of this moment (FSA_RELEASE_URL or GitHub Releases, as `fsa
 * upgrade` uses it), so the app reads exactly what an upgrade would; `off` removes both. Only runtime.json changes, which
 * the scheduler reads before each run: no service restarts.
 */
final readonly class UpdateCheckApplier implements SettingApplier
{
    public const string KEY = 'update-check';

    public function __construct(private HostFacts $facts) {}

    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        $value = strtolower(trim($request->value));
        if (! in_array($value, ['on', 'off'], true)) {
            throw new InvalidInput('The update check is `on` or `off`.', 'invalid_value', 'For example `fsa config set update-check on`; it reads only the release manifest, once a day.');
        }
        $before = InstanceSettings::of($state)->updateManifestUrl() === null ? 'off' : 'on';
        $url = $value === 'on' ? ReleaseFeed::base($this->facts).'/latest/download/'.ManifestVerifier::MANIFEST : null;
        $after = $state->withSettings(['update_check' => $value === 'on' ? true : null, 'update_url' => $url]);

        return new ConfigChange(self::KEY, $state, $after, $before, $value);
    }

    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        return $change;
    }
}
