<?php

declare(strict_types=1);

namespace App\Domain\Platform\Health\Checks;

use App\Domain\Platform\Health\HealthCheck;
use App\Domain\Platform\Health\HealthResult;
use App\Domain\Platform\Health\SystemProbes;

/**
 * The instance's default mail transport: SMTP is probed with a 2 s TCP connect (no login, no mail sent);
 * `log`/`array` warn because mail is not delivered; API transports are reported as configured, not probed.
 * Capture mode (config `mail.capture`, set by fieldset; ADR 0080) warns too, whether or not the catcher is reachable: the
 * mail arrives in the bundled catcher, not at its recipients. Organization SMTP settings (§8.8) are tested from the
 * organization's mail settings page, not here.
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
        if (config('mail.capture') === true) {
            return $this->captured($mailer, $details);
        }

        return match ($transport) {
            'smtp' => $this->smtp($mailer, $details),
            'log', 'array' => HealthResult::warn($this->key(), (string) __('platform.health.mail.not_delivered'), $details),
            default => HealthResult::ok($this->key(), (string) __('platform.health.mail.not_probed'), $details),
        };
    }

    /** @param  array<string, string|int>  $details */
    private function captured(string $mailer, array $details): HealthResult
    {
        [$host, $port] = $this->endpoint($mailer);
        $details[(string) __('platform.health.endpoint')] = $host.':'.$port;
        $reachable = $host !== '' && $this->probes->tcpReachable($host, $port);
        $details[(string) __('platform.health.mail.catcher')] = (string) __($reachable ? 'platform.health.mail.catcher_reachable' : 'platform.health.mail.catcher_unreachable');

        return HealthResult::warn($this->key(), (string) __('platform.health.mail.captured'), $details);
    }

    /** @param  array<string, string|int>  $details */
    private function smtp(string $mailer, array $details): HealthResult
    {
        [$host, $port] = $this->endpoint($mailer);
        $details[(string) __('platform.health.endpoint')] = $host.':'.$port;

        return $host !== '' && $this->probes->tcpReachable($host, $port)
            ? HealthResult::ok($this->key(), (string) __('platform.health.mail.reachable'), $details)
            : HealthResult::fail($this->key(), (string) __('platform.health.mail.unreachable'), $details);
    }

    /** @return array{0: string, 1: int} */
    private function endpoint(string $mailer): array
    {
        return [(string) config("mail.mailers.{$mailer}.host", ''), (int) config("mail.mailers.{$mailer}.port", 25)];
    }
}
