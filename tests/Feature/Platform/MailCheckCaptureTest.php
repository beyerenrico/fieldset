<?php

declare(strict_types=1);

use App\Domain\Platform\Health\Checks\MailCheck;
use App\Domain\Platform\Health\HealthStatus;
use App\Domain\Platform\Health\SystemProbes;

/*
 * Plan U12 (ADR 0080): in capture mode the mail health check warns, whether or not the catcher answers: the mail
 * reaches the bundled catcher, not its recipients. A reachable SMTP server without capture stays "ok".
 */

beforeEach(function (): void {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mailpit', 'mail.mailers.smtp.port' => 1025]);
});

it('warns in capture mode even though the catcher is reachable', function (bool $reachable, string $catcher): void {
    config(['mail.capture' => true]);
    $this->mock(SystemProbes::class, fn ($mock) => $mock->shouldReceive('tcpReachable')->with('mailpit', 1025)->andReturn($reachable));

    $result = app(MailCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Warn)
        ->and($result->summary)->toBe(__('platform.health.mail.captured'))
        ->and($result->details[__('platform.health.mail.catcher')])->toBe(__($catcher))
        ->and($result->details[__('platform.health.endpoint')])->toBe('mailpit:1025');
})->with([
    'catcher up' => [true, 'platform.health.mail.catcher_reachable'],
    'catcher down' => [false, 'platform.health.mail.catcher_unreachable'],
]);

it('reports a reachable SMTP server as ok without capture mode', function (): void {
    config(['mail.capture' => false]);
    $this->mock(SystemProbes::class, fn ($mock) => $mock->shouldReceive('tcpReachable')->andReturn(true));

    expect(app(MailCheck::class)->run()->status)->toBe(HealthStatus::Ok);
});
