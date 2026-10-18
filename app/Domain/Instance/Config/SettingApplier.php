<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\State\InstanceState;

/**
 * One settable key of `fsa config set` (R32, ADR 0077). ConfigSetter runs every applier the same way: plan
 * (validate, exit 2) → confirm when the URL changes (exit 6) → lock and journal → preflight (exit 3) → apply the
 * rendered target → verify → revert on failure.
 */
interface SettingApplier
{
    /**
     * Validates the value against the state (InputGrammar, mode rules, R61 exclusions) and plans the target state.
     * A value equal to the current one plans a change whose `unchanged()` is true, without any Docker call; only
     * values that need the engine to resolve them (a proxy container's address) may inspect it read-only.
     *
     * @throws InstanceException exit 2 for an invalid value or a key that does not apply in this mode
     */
    public function plan(InstanceState $state, ConfigRequest $request): ConfigChange;

    /**
     * Checks under the lock, before anything changes: DNS, free ports, the proxy on its network. May refine the
     * target (a port chosen because the remembered one is taken).
     *
     * @throws InstanceException exit 3 when a check fails
     */
    public function preflight(ConfigChange $change, HostFacts $facts): ConfigChange;
}
