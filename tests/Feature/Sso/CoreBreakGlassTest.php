<?php

declare(strict_types=1);

use App\Domain\Identity\Auth\SharedAppAuthentication;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enforcement\DomainPasswordGate;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Sso\FakeOidcIdp;

/*
 * Gate review M7, finding 10: under enforced SSO the break-glass address must not stand out — a wrong
 * break-glass password gets the same answer as any other address of the domain, every enforced address
 * costs one password hash, and forgot-password answers alike.
 */
final class CoreBreakGlassCountingHasher implements Hasher
{
    public int $checks = 0;

    public function __construct(private readonly Hasher $inner) {}

    public function info($hashedValue): array
    {
        return $this->inner->info($hashedValue);
    }

    public function make(#[SensitiveParameter] $value, array $options = []): string
    {
        return $this->inner->make($value, $options);
    }

    public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        $this->checks++;

        return $this->inner->check($value, $hashedValue, $options);
    }

    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->inner->needsRehash($hashedValue, $options);
    }

    /** @param  array<int, mixed>  $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }
}

beforeEach(function (): void {
    Notification::fake();
    $this->reports = FakeOidcIdp::spyReporter();
    $this->org = makeOrganization(name: 'Acme GmbH');
    $this->owner = ownerOf($this->org);
    $this->owner->forceFill(['email' => 'owner@acme.example', 'password' => 'owner-pass-123'])->save();
    $mfa = SharedAppAuthentication::make()->recoverable();
    $mfa->saveSecret($this->owner, $mfa->generateSecret());
    app(TenantContext::class)->run($this->org, function (): void {
        SsoDomain::factory()->verified()->create(['domain' => 'acme.example']);
        IdentityProvider::factory()->enforcing($this->owner)->create();
    });
    User::factory()->memberOf($this->org)->create(['email' => 'worker@acme.example', 'password' => 'worker-pass-123']);
});

function coreBreakGlassCounter(): CoreBreakGlassCountingHasher
{
    $counter = new CoreBreakGlassCountingHasher(app('hash'));
    Hash::swap($counter);

    return $counter;
}

it('answers a wrong break-glass password exactly like any other enforced address', function (): void {
    $worker = $this->postJson('/auth/login', ['email' => 'worker@acme.example', 'password' => 'nope'])->assertUnprocessable();
    $breakGlass = $this->postJson('/auth/login', ['email' => 'owner@acme.example', 'password' => 'nope'])->assertUnprocessable();
    $unknown = $this->postJson('/auth/login', ['email' => 'nobody@acme.example', 'password' => 'nope'])->assertUnprocessable();

    expect($breakGlass->json())->toBe($worker->json())->toBe($unknown->json())
        ->and($breakGlass->json('errors'))->toHaveKey('sso_required')->not->toHaveKey('email');
    $this->assertGuest();
    expect($this->reports->getArrayCopy())->toBe([]);
});

it('spends one password hash on every enforced address, break-glass or not', function (string $email, string $password): void {
    $counter = coreBreakGlassCounter();

    expect(fn () => app(DomainPasswordGate::class)->assertPasswordLoginWith($email, $password))->toThrow(SsoRequiredException::class)
        ->and($counter->checks)->toBe(1);
})->with([
    'member' => ['worker@acme.example', 'worker-pass-123'],
    'unknown' => ['nobody@acme.example', 'whatever'],
    'break-glass, wrong password' => ['owner@acme.example', 'nope'],
]);

it('lets the break-glass Owner with the correct password through to the MFA challenge', function (): void {
    app(DomainPasswordGate::class)->assertPasswordLoginWith('Owner@Acme.example', 'owner-pass-123');

    $this->postJson('/auth/login', ['email' => 'owner@acme.example', 'password' => 'owner-pass-123'])->assertOk()->assertJson(['two_factor' => true]);
});

it('answers forgot-password alike, while the break-glass Owner still gets the link', function (): void {
    $breakGlass = $this->postJson('/auth/forgot-password', ['email' => 'owner@acme.example'])->assertUnprocessable();
    $worker = $this->postJson('/auth/forgot-password', ['email' => 'worker@acme.example'])->assertUnprocessable();

    expect($breakGlass->json())->toBe($worker->json())->and($breakGlass->json('errors'))->toHaveKey('sso_required');
    Notification::assertSentTo($this->owner, ResetPassword::class);
    Notification::assertSentTimes(ResetPassword::class, 1);
});
