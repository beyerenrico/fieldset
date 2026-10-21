<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\SettingApplier;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fieldset config set webhooks on|paused` (plan U17, ADR 0085): whether workflow webhooks go out. `fieldset install --from-backup`
 * starts a moved instance with them paused, so the old and the new server never both deliver; `on` is the operator's
 * confirmation that the move is complete (DNS switched, the old server stopped). It only rewrites runtime.json, which
 * the queue reads per job: no service restarts. Held webhook steps are taken up again by the stalled-run sweep.
 */
final readonly class WebhooksApplier implements SettingApplier
{
    public const string KEY = 'webhooks';

    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange
    {
        $value = strtolower(trim($request->value));
        if (! in_array($value, ['on', 'paused'], true)) {
            throw new InvalidInput('Webhooks are `on` or `paused`.', 'invalid_value', 'After moving an instance: `fieldset config set webhooks on` once the old server no longer runs.');
        }
        $before = InstanceSettings::of($state)->webhooksPaused() ? 'paused' : 'on';

        return new ConfigChange(self::KEY, $state, $state->withSetting('webhooks_paused', $value === 'paused' ? true : null), $before, $value);
    }

    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange
    {
        return $change;
    }
}
