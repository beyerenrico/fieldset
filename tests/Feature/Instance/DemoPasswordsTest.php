<?php

declare(strict_types=1);

use App\Console\Commands\DemoSeedCommand;
use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Demo\DefaultDemoPassword;
use App\Domain\Identity\Demo\DemoAccounts;
use App\Domain\Identity\Demo\DemoPasswordSource;
use App\Domain\Identity\Demo\RandomDemoPasswords;
use App\Domain\Identity\Demo\RotateDemoPasswords;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Models\Workflow;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Documents\DocumentFixtures;

/*
 * U13 / M11-T02 (BUILD_PROMPT_V2 R29, AE2, KTD25, ADR 0081): the app side of demo data. DemoSeeder keeps `password` for
 * development, tests and E2E; `app:demo-seed --passwords=random` gives every demo user a random password printed once
 * under `secrets`; `app:demo-passwords --rotate` rotates them and revokes sessions and API tokens; `--check` finds every
 * account that still signs in with `password` (U14 refuses tunnel and local CA while there is one).
 */

beforeEach(fn () => DocumentFixtures::fakeDisk());

/** @return array{0: array<string, mixed>, 1: int, 2: string} the JSON document, the exit code and the raw output */
function demoCommand(string $command, array $options = []): array
{
    $exit = Artisan::call($command, [...$options, '--json' => true]);
    $output = trim(Artisan::output());
    $lines = array_values(array_filter(explode("\n", $output)));

    expect($lines)->toHaveCount(1);

    return [json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR), $exit, $output];
}

/** @param  array<string, mixed>  $json  @return array<string, string> email => password */
function demoSecrets(array $json): array
{
    $passwords = [];
    foreach ($json['secrets'] as $secret) {
        $passwords[substr((string) $secret['name'], strlen(DemoSeedCommand::SECRET_PREFIX))] = $secret['value'];
    }

    return $passwords;
}

function demoUser(string $email): User
{
    return User::query()->where('email', $email)->sole();
}

it('keeps the documented password for the plain seeder (development, tests, E2E)', function (): void {
    $this->seed(DemoSeeder::class);

    expect(DemoAccounts::users()->pluck('email')->all())->toEqualCanonicalizing(DemoAccounts::emails())
        ->and(DemoAccounts::users()->every(fn (User $user): bool => Hash::check(DefaultDemoPassword::PASSWORD, $user->password)))->toBeTrue()
        ->and(DemoSeeder::PASSWORD)->toBe('password')
        ->and(app(DemoPasswordSource::class))->toBeInstanceOf(DefaultDemoPassword::class);
});

it('seeds with one random password per demo user, listed once under secrets and never in data', function (): void {
    clearAuditLog();
    [$json, $exit, $output] = demoCommand('app:demo-seed', ['--passwords' => 'random', '--actor-uid' => '1000']);
    $passwords = demoSecrets($json);
    $audit = Activity::query()->where('event', 'instance.demo_seeded')->sole();

    expect($exit)->toBe(0)
        ->and($json)->toMatchArray(['command' => 'app:demo-seed', 'status' => 'ok', 'changed' => true])
        ->and($json['data'])->toMatchArray(['seeded' => true, 'passwords' => 'random', 'login_url' => url('/admin/login')])
        ->and($json['data'])->not->toHaveKey('default_password')
        ->and($json['data']['organization']['slug'])->toBe('demo')
        ->and(array_column($json['data']['users'], 'email'))->toBe(DemoAccounts::emails())
        ->and(array_keys($passwords))->toBe(DemoAccounts::emails())
        ->and(array_unique($passwords))->toHaveCount(count(DemoAccounts::emails()))
        ->and(array_map(strlen(...), array_values($passwords)))->each->toBe(RandomDemoPasswords::LENGTH)
        ->and(array_column($json['secrets'], 'expires_at'))->each->toBeNull()
        ->and(json_encode($json['data']))->not->toContain(reset($passwords))
        ->and($audit->actor_type)->toBe('cli')
        ->and($audit->properties['host_uid'])->toBe(1000)
        ->and($audit->properties['mode'])->toBe('random')
        ->and(Activity::query()->where('event', 'auth.password_changed')->count())->toBe(0);
    foreach ($passwords as $email => $password) {
        expect(Hash::check($password, demoUser($email)->password))->toBeTrue("{$email} signs in with its printed password")
            ->and(Hash::check('password', demoUser($email)->password))->toBeFalse();
    }
    expect(substr_count($output, (string) reset($passwords)))->toBe(1);
});

it('seeds with the documented password in data, never as a secret, for a loopback-only instance', function (): void {
    [$json] = demoCommand('app:demo-seed');

    expect($json['data'])->toMatchArray(['seeded' => true, 'passwords' => 'default', 'default_password' => 'password'])
        ->and($json['secrets'])->toBe([])
        ->and(Hash::check('password', demoUser('worker@demo.test')->password))->toBeTrue();
});

it('changes nothing on a second run and reports the demo accounts still on the default password', function (): void {
    demoCommand('app:demo-seed');
    [$json, $exit] = demoCommand('app:demo-seed', ['--passwords' => 'random']);

    expect($exit)->toBe(0)
        ->and($json['changed'])->toBeFalse()
        ->and($json['data'])->toMatchArray(['seeded' => false, 'passwords' => null, 'default_password_users' => 9, 'deleted' => false])
        ->and($json['secrets'])->toBe([])
        ->and($json['warnings'][0]['code'])->toBe('already_seeded')
        ->and(Organization::query()->where('slug', 'demo')->count())->toBe(1);
});

it('keeps the password of an account that existed before and lists no password for it', function (): void {
    User::factory()->create(['email' => 'admin@demo.test', 'password' => 'my-own-password-1']);

    [$json] = demoCommand('app:demo-seed', ['--passwords' => 'random']);

    expect(demoSecrets($json))->not->toHaveKey('admin@demo.test')
        ->and(demoSecrets($json))->toHaveCount(count(DemoAccounts::emails()) - 1)
        ->and(Hash::check('my-own-password-1', demoUser('admin@demo.test')->password))->toBeTrue();
});

it('rolls the whole seeding back when a late step fails', function (): void {
    Workflow::created(fn () => throw new RuntimeException('workflow broken'));

    expect(fn () => Artisan::call('app:demo-seed', ['--passwords' => 'random', '--json' => true]))->toThrow(RuntimeException::class, 'workflow broken');
    expect(DemoAccounts::organization())->toBeNull()
        ->and(DemoAccounts::users())->toHaveCount(0)
        ->and(Activity::query()->where('event', 'instance.demo_seeded')->count())->toBe(0);
});

it('refuses an unknown password mode or actor UID', function (array $options): void {
    [$json, $exit] = demoCommand('app:demo-seed', $options);

    expect($exit)->toBe(1)->and($json['errors'][0]['code'])->toBe('invalid_input')->and(DemoAccounts::organization())->toBeNull();
})->with([[['--passwords' => 'loopback']], [['--actor-uid' => 'root']]]);

it('finds every account that still signs in with password, demo or not, and skips unknown passwords', function (): void {
    $this->seed(DemoSeeder::class);
    User::factory()->create(['email' => 'real@example.com', 'password' => 'password']);
    User::factory()->create(['email' => 'safe@example.com', 'password' => 'a-long-safe-password']);
    $unknown = User::factory()->create(['email' => 'link@example.com', 'password' => 'password']);
    $unknown->forceFill(['password_known' => false])->saveQuietly();

    [$json, $exit] = demoCommand('app:demo-passwords', ['--check' => true]);

    expect($exit)->toBe(0)
        ->and($json['changed'])->toBeFalse()
        ->and($json['data']['count'])->toBe(10)
        ->and($json['data']['checked'])->toBe(11)
        ->and($json['data']['users'])->toContain(['email' => 'real@example.com', 'demo' => false], ['email' => 'owner@demo.test', 'demo' => true])
        ->and(array_column($json['data']['users'], 'email'))->not->toContain('safe@example.com', 'link@example.com');
});

it('rotates every demo password, revokes sessions, remember tokens and API tokens, and audits each account', function (): void {
    $this->seed(DemoSeeder::class);
    $worker = demoUser('worker@demo.test');
    $worker->createToken('field-app');
    $worker->forceFill(['remember_token' => 'remember-me-token'])->saveQuietly();
    DB::table('sessions')->insert(['id' => 'demo-session-1', 'user_id' => $worker->id, 'payload' => '', 'last_activity' => time()]);
    $other = ownerOf(makeOrganization());
    $other->forceFill(['password' => 'not-the-demo-password'])->saveQuietly();
    DB::table('sessions')->insert(['id' => 'other-session-1', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()]);
    clearAuditLog();

    [$json, $exit] = demoCommand('app:demo-passwords', ['--rotate' => true, '--actor-uid' => '501']);
    $passwords = demoSecrets($json);
    $audits = Activity::query()->where('event', RotateDemoPasswords::EVENT)->get();
    [$check] = demoCommand('app:demo-passwords', ['--check' => true]);

    expect($exit)->toBe(0)
        ->and($json['changed'])->toBeTrue()
        ->and($json['data'])->toBe(['users' => DemoAccounts::emails(), 'sessions_ended' => 1, 'tokens_revoked' => 1])
        ->and(array_keys($passwords))->toBe(DemoAccounts::emails())
        ->and(Hash::check($passwords['worker@demo.test'], $worker->fresh()?->password))->toBeTrue()
        ->and(Hash::check('password', $worker->fresh()?->password))->toBeFalse()
        ->and($worker->tokens()->count())->toBe(0)
        ->and($worker->fresh()?->remember_token)->toBeNull()
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['other-session-1'])
        ->and($audits)->toHaveCount(9)
        ->and($audits->every(fn (Activity $a): bool => $a->actor_type === 'cli' && $a->properties['host_uid'] === 501))->toBeTrue()
        ->and(Activity::query()->where('event', 'auth.password_changed')->count())->toBe(0)
        ->and($check['data']['count'])->toBe(0);
});

it('rotates nothing without demo accounts and refuses a call without exactly one action', function (): void {
    [$none, $noneExit] = demoCommand('app:demo-passwords', ['--rotate' => true]);
    [$neither, $neitherExit] = demoCommand('app:demo-passwords');
    [$both] = demoCommand('app:demo-passwords', ['--rotate' => true, '--check' => true]);

    expect($noneExit)->toBe(0)
        ->and($none['changed'])->toBeFalse()
        ->and($none['warnings'][0]['code'])->toBe('no_demo_users')
        ->and($none['secrets'])->toBe([])
        ->and($neitherExit)->toBe(1)
        ->and($neither['errors'][0]['code'])->toBe('invalid_input')
        ->and($both['errors'][0]['code'])->toBe('invalid_input');
});

it('prints the passwords once in human mode', function (): void {
    Artisan::call('app:demo-seed', ['--passwords' => 'random']);
    $seeded = Artisan::output();
    Artisan::call('app:demo-passwords', ['--check' => true]);
    $checked = Artisan::output();
    Artisan::call('app:demo-passwords', ['--rotate' => true]);
    $rotated = Artisan::output();

    expect($seeded)->toContain('owner@demo.test: ')->not->toContain('owner@demo.test: password')
        ->and($checked)->toContain('No account signs in with the demo password')
        ->and($rotated)->toContain('New demo passwords (shown once)');
});
