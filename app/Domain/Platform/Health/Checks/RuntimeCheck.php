<?php

declare(strict_types=1);

namespace App\Domain\Platform\Health\Checks;

use App\Domain\Instance\Runtime\RuntimeReading;
use App\Domain\Instance\Runtime\RuntimeSource;
use App\Domain\Instance\Runtime\RuntimeState;
use App\Domain\Instance\Runtime\RuntimeStateReader;
use App\Domain\Platform\Health\HealthCheck;
use App\Domain\Platform\Health\HealthResult;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The instance runtime file (plan U10, KTD11, ADR 0074): ok when current or not a `fieldset` instance; warn while an
 * unusable file leaves the last good value in force; fail when nothing good was read and APP_URL applies with the
 * gate counting as raised. Web requests are mostly gated in that last case, so the log carries the same warning.
 */
final readonly class RuntimeCheck implements HealthCheck
{
    public function __construct(private RuntimeStateReader $reader) {}

    public function key(): string
    {
        return 'runtime';
    }

    public function run(): HealthResult
    {
        $reading = $this->reader->reading();
        $summary = (string) __('platform.health.runtime.'.$reading->source->value);
        if (! $reading->state instanceof RuntimeState) {
            return HealthResult::ok($this->key(), $summary);
        }
        $details = $this->details($reading->state, $reading);

        return match ($reading->source) {
            RuntimeSource::LastGood => HealthResult::warn($this->key(), $summary, $details),
            RuntimeSource::Fallback => HealthResult::fail($this->key(), $summary, $details),
            default => HealthResult::ok($this->key(), (string) __('platform.health.runtime.ok'), $details),
        };
    }

    /** @return array<string, string|CarbonInterface> */
    private function details(RuntimeState $state, RuntimeReading $reading): array
    {
        $now = CarbonImmutable::now();
        $tunnel = $state->tunnel;
        $details = [(string) __('platform.health.runtime.url') => $state->publicUrl($now)];
        if ($tunnel->hostname !== null) {
            $details[(string) __('platform.health.runtime.tunnel')] = $tunnel->isActive($now)
                ? $tunnel->hostname
                : (string) __('platform.health.runtime.tunnel_expired', ['host' => $tunnel->hostname]);
        }
        if ($tunnel->expiresAt instanceof CarbonImmutable) {
            $details[(string) __('platform.health.runtime.tunnel_expires')] = $tunnel->expiresAt;
        }
        $details[(string) __('platform.health.runtime.gate')] = $state->gate->raised
            ? (string) __('platform.health.runtime.gate_raised', ['operation' => $state->gate->operation ?? '—'])
            : (string) __('platform.health.runtime.gate_lowered');
        if ($reading->problem !== null) {
            $details[(string) __('platform.health.runtime.problem')] = $reading->problem;
        }

        return $details;
    }
}
