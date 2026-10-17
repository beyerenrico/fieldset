<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Actions\IssueLoginLink;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\LoginLink;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/*
 * The app-side commands behind `fsa login-link` and `fsa status` (KTD25: socket-less one-offs whose JSON fsa
 * passes through). The link appears only under `secrets`; listings never show a token.
 */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->admin = memberOf($this->org, SystemRole::Admin);
});

/** @return array{0: array<string, mixed>, 1: string, 2: int} the JSON document, the raw output and the exit code */
function loginLinkCommandJson(string $command, array $arguments): array
{
    $exit = Artisan::call($command, [...$arguments, '--json' => true]);
    $output = Artisan::output();
    $lines = array_values(array_filter(explode("\n", trim($output))));
    expect($lines)->toHaveCount(1);

    return [json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR), $output, $exit];
}

it('issues a link for an existing admin as one JSON document with the link only under secrets', function (): void {
    $this->freezeTime();

    [$json, , $exit] = loginLinkCommandJson('identity:login-link', ['email' => strtoupper($this->admin->email), '--actor-uid' => '501']);

    $secret = $json['secrets'][0];
    $token = Str::after($secret['value'], '#');
    expect($exit)->toBe(0)
        ->and($json)->toMatchArray(['schema_version' => 1, 'command' => 'identity:login-link', 'status' => 'ok', 'changed' => true, 'warnings' => [], 'errors' => []])
        ->and($json['data'])->toMatchArray(['email' => $this->admin->email, 'user_id' => $this->admin->id, 'kind' => 'admin'])
        ->and($json['data']['expires_at'])->toBe(now()->addMinutes(15)->utc()->toIso8601ZuluString())
        ->and($secret)->toMatchArray(['name' => 'sign_in_link', 'expires_at' => $json['data']['expires_at']])
        ->and($secret['value'])->toBe(url('/login-link').'#'.$token)
        ->and(json_encode($json['data']))->not->toContain($token)
        ->and(Activity::query()->where('event', 'login_link.issued')->sole()->properties['host_uid'])->toBe(501);
});

it('prints the link for a human without JSON', function (): void {
    $this->artisan('identity:login-link', ['email' => $this->admin->email])
        ->expectsOutputToContain('single use')
        ->expectsOutputToContain(url('/login-link').'#')
        ->assertSuccessful();
});

it('reports refusals with stable error codes', function (string $case, string $code, bool $retryable): void {
    $email = match ($case) {
        'unknown' => 'nobody@example.com',
        'field worker' => memberOf($this->org, SystemRole::FieldWorker)->email,
        default => $this->admin->email,
    };
    if ($case === 'rate limited') {
        foreach (range(1, IssueLoginLink::MAX_PER_WINDOW) as $ignored) {
            Artisan::call('identity:login-link', ['email' => $email]);
        }
    }
    $arguments = ['email' => $email, ...($case === 'bad uid' ? ['--actor-uid' => '-1'] : [])];

    [$json, , $exit] = loginLinkCommandJson('identity:login-link', $arguments);

    expect($exit)->toBe(1)
        ->and($json['status'])->toBe('failed')
        ->and($json['secrets'])->toBe([])
        ->and($json['errors'][0]['code'])->toBe($code)
        ->and($json['errors'][0]['retryable'])->toBe($retryable);
})->with([
    ['unknown', 'user_not_found', false],
    ['field worker', 'not_admin', false],
    ['bad uid', 'invalid_input', false],
    ['rate limited', 'rate_limited', true],
]);

it('lists unused links for fsa status without any token', function (): void {
    $used = memberOf($this->org, SystemRole::Admin);
    $usedToken = Str::after(app(IssueLoginLink::class)->handle($used)->url, '#');
    $this->post('/login-link', ['token' => $usedToken]);
    $expired = app(IssueLoginLink::class)->handle(memberOf($this->org, SystemRole::Admin));
    $expired->link->forceFill(['expires_at' => now()->subMinute()])->save();
    $open = app(IssueLoginLink::class)->handle($this->admin, 1000);

    [$json, $output] = loginLinkCommandJson('identity:login-links', ['--unused' => true]);
    [$all] = loginLinkCommandJson('identity:login-links', []);

    expect($json['data']['count'])->toBe(1)
        ->and($json['data']['links'][0])->toMatchArray([
            'id' => $open->link->id, 'email' => $this->admin->email, 'kind' => 'admin', 'state' => 'unused',
            'used_at' => null, 'created_by' => 'cli', 'created_by_uid' => 1000,
        ])
        ->and($output)->not->toContain(Str::after($open->url, '#'))
        ->and(array_column($all['data']['links'], 'state'))->toEqualCanonicalizing(['unused', 'expired', 'used'])
        ->and(LoginLink::query()->count())->toBe(3);
});
