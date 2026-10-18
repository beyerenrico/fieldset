<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Docker\ProcessOutcome;

/**
 * Scripted answers of the app-side demo one-offs (`app:demo-seed`, `app:demo-passwords`; ADR 0081) for the
 * `fsa demo` and `fsa install --demo` tests: the JSON documents DemoSeedCommand and DemoPasswordsCommand print.
 */
final class DemoFixtures
{
    public const array EMAILS = [
        'owner@demo.test', 'admin@demo.test', 'designer@demo.test', 'reviewer@demo.test', 'coordinator@demo.test',
        'worker@demo.test', 'mobile@demo.test', 'tablet@demo.test', 'viewer@demo.test',
    ];

    public const string LOGIN_URL = 'http://localhost:8080/admin/login';

    /** A recognisable fake password per account and round, e.g. `pw-seed-worker-0123456789`. */
    public static function password(string $email, string $round = 'seed'): string
    {
        return 'pw-'.$round.'-'.explode('@', $email)[0].'-0123456789';
    }

    /** `app:demo-seed` creating the demo: random passwords under secrets, or the default one in data. */
    public static function seeded(string $mode): ProcessOutcome
    {
        return InstanceCommandFixture::oneOffJson([
            'command' => 'app:demo-seed', 'status' => 'ok', 'changed' => true,
            'data' => [...self::summary(), 'seeded' => true, 'passwords' => $mode, ...($mode === 'default' ? ['default_password' => 'password'] : [])],
            'secrets' => $mode === 'random' ? self::secrets('seed') : [],
        ]);
    }

    /** `app:demo-seed` finding the demo already there, `$defaults` of its accounts still on `password`. */
    public static function existing(int $defaults): ProcessOutcome
    {
        return InstanceCommandFixture::oneOffJson([
            'command' => 'app:demo-seed', 'status' => 'ok',
            'data' => [...self::summary(), 'seeded' => false, 'passwords' => null, 'default_password_users' => $defaults, 'deleted' => false],
            'warnings' => [['code' => 'already_seeded', 'message' => 'The demo organization exists already.', 'hint' => null]],
        ]);
    }

    /** `app:demo-passwords --rotate`: every demo account with a new password. */
    public static function rotated(): ProcessOutcome
    {
        return InstanceCommandFixture::oneOffJson([
            'command' => 'app:demo-passwords', 'status' => 'ok', 'changed' => true,
            'data' => ['users' => self::EMAILS, 'sessions_ended' => 2, 'tokens_revoked' => 1],
            'secrets' => self::secrets('rotated'),
        ]);
    }

    /** `app:demo-passwords --rotate` without demo accounts. */
    public static function noDemoUsers(): ProcessOutcome
    {
        return InstanceCommandFixture::oneOffJson([
            'command' => 'app:demo-passwords', 'status' => 'ok',
            'data' => ['users' => [], 'sessions_ended' => 0, 'tokens_revoked' => 0],
            'warnings' => [['code' => 'no_demo_users', 'message' => 'There are no demo accounts.', 'hint' => null]],
        ]);
    }

    /** @return array<string, mixed> */
    private static function summary(): array
    {
        return [
            'organization' => ['id' => 2, 'name' => 'Demo Haustechnik GmbH', 'slug' => 'demo'],
            'users' => array_map(static fn (string $email): array => ['email' => $email, 'name' => ucfirst(explode('@', $email)[0]), 'roles' => ['viewer']], self::EMAILS),
            'login_url' => self::LOGIN_URL,
        ];
    }

    /** @return list<array{name: string, value: string, expires_at: null}> */
    private static function secrets(string $round): array
    {
        return array_map(static fn (string $email): array => ['name' => 'demo_password:'.$email, 'value' => self::password($email, $round), 'expires_at' => null], self::EMAILS);
    }
}
