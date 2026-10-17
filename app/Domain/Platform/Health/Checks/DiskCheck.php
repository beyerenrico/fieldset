<?php

declare(strict_types=1);

namespace App\Domain\Platform\Health\Checks;

use App\Domain\Platform\Health\HealthCheck;
use App\Domain\Platform\Health\HealthResult;
use App\Domain\Platform\Health\SystemProbes;
use Illuminate\Support\Number;

/** Free space on the volume holding storage/ (local attachments, renditions, logs): < 10 % warns, < 5 % fails. */
final readonly class DiskCheck implements HealthCheck
{
    public function __construct(private SystemProbes $probes) {}

    public function key(): string
    {
        return 'disk';
    }

    public function run(): HealthResult
    {
        $space = $this->probes->diskSpace(storage_path());
        if ($space === null) {
            return HealthResult::fail($this->key(), (string) __('platform.health.disk.unknown'));
        }

        $percent = (int) floor($space['free'] / $space['total'] * 100);
        // Locale-aware numbers: "211.6 GB" in English, "211,6 GB" in German.
        $details = Number::withLocale(app()->getLocale(), fn (): array => [
            (string) __('platform.health.disk.free') => Number::fileSize((int) $space['free'], precision: 1),
            (string) __('platform.health.disk.total') => Number::fileSize((int) $space['total'], precision: 1),
        ]);
        $summary = (string) __('platform.health.disk.percent_free', ['percent' => $percent]);

        return match (true) {
            $percent < 5 => HealthResult::fail($this->key(), $summary, $details),
            $percent < 10 => HealthResult::warn($this->key(), $summary, $details),
            default => HealthResult::ok($this->key(), $summary, $details),
        };
    }
}
