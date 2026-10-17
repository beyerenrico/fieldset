<?php

declare(strict_types=1);

namespace App\Domain\Platform\Health\Checks;

use App\Domain\Platform\Health\HealthCheck;
use App\Domain\Platform\Health\HealthResult;
use App\Domain\Platform\Health\SystemProbes;

/**
 * The instance's default mail transport: SMTP is probed with a 2 s TCP connect (no login, no mail sent);
 * `log`/`array` warn because mail is not delivered; API transports are reported as configured, not probed.
 * Organization SMTP settings (§8.8) are tested from the organization's mail settings page, not here.
 */
final readonly class MailCheck implements HealthCheck
{
    public function __construct(private SystemProbes $probes) {}

    public function key(): string
    {
        return 'mail';
    }

    public function run(): HealthResult
    {
        $mailer = (string) config('mail.default', 'log');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        $details = [
            (string) __('platform.health.mail.mailer') => $mailer,
            (string) __('platform.health.mail.transport') => $transport,
        ];

        return match ($transport) {
            'smtp' => $this->smtp($mailer, $details),
            'log', 'array' => HealthResult::warn($this->key(), (string) __('platform.health.mail.not_delivered'), $details),
            default => HealthResult::ok($this->key(), (string) __('platform.health.mail.not_probed'), $details),
        };
    }

    /** @param  array<string, string|int>  $details */
    private function smtp(string $mailer, array $details): HealthResult
    {
        $host = (string) config("mail.mailers.{$mailer}.host", '');
        $port = (int) config("mail.mailers.{$mailer}.port", 25);
        $details[(string) __('platform.health.endpoint')] = $host.':'.$port;

        return $host !== '' && $this->probes->tcpReachable($host, $port)
            ? HealthResult::ok($this->key(), (string) __('platform.health.mail.reachable'), $details)
            : HealthResult::fail($this->key(), (string) __('platform.health.mail.unreachable'), $details);
    }
}
