<?php

declare(strict_types=1);

use App\Providers\SsoServiceProvider;

/* M7 review: dev-only SSO settings must never run in production. */

it('refuses the insecure IdP mode and config DNS in production', function (array $settings): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['sso.allow_insecure_idp' => false, 'sso.dns.driver' => 'dns', ...$settings]);

    expect(fn () => SsoServiceProvider::refuseDevSettingsInProduction())->toThrow(RuntimeException::class);
})->with([
    'insecure IdP' => [['sso.allow_insecure_idp' => true]],
    'config DNS' => [['sso.dns.driver' => 'config']],
]);

it('allows them outside production and allows safe settings in production', function (): void {
    config(['sso.allow_insecure_idp' => true, 'sso.dns.driver' => 'config']);
    SsoServiceProvider::refuseDevSettingsInProduction();

    app()->detectEnvironment(fn (): string => 'production');
    config(['sso.allow_insecure_idp' => false, 'sso.dns.driver' => 'dns']);
    SsoServiceProvider::refuseDevSettingsInProduction();

    expect(true)->toBeTrue();
});
