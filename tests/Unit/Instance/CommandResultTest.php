<?php

declare(strict_types=1);

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\Message;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Output\ResultStatus;
use App\Domain\Instance\Output\Secret;
use App\Domain\Instance\Output\SecretRedactor;
use Carbon\CarbonImmutable;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(fn () => CarbonImmutable::setTestNow());

it('always carries schema_version and status, in a fixed key order', function (): void {
    $envelope = CommandResult::ok('status')->toArray();

    expect($envelope)->toBe([
        'schema_version' => 1, 'command' => 'status', 'status' => 'ok', 'changed' => false,
        'data' => [], 'secrets' => [], 'warnings' => [], 'errors' => [],
    ]);
});

it('derives the status from the exit code', function (ExitCode $code, ResultStatus $status): void {
    expect(CommandResult::failed('upgrade', $code, 'x', 'y')->status())->toBe($status);
})->with([
    [ExitCode::Success, ResultStatus::Ok],
    [ExitCode::InternalError, ResultStatus::Failed],
    [ExitCode::InvalidInput, ResultStatus::Failed],
    [ExitCode::PreflightFailed, ResultStatus::Failed],
    [ExitCode::HealthCheckFailed, ResultStatus::Failed],
    [ExitCode::RolledBack, ResultStatus::RolledBack],
    [ExitCode::ConfirmationRequired, ResultStatus::Aborted],
    [ExitCode::Incompatible, ResultStatus::Failed],
    [ExitCode::RollbackFailed, ResultStatus::RollbackFailed],
    [ExitCode::AbortedUnchanged, ResultStatus::Aborted],
    [ExitCode::DockerUnavailable, ResultStatus::Failed],
]);

it('knows the exit codes of BUILD_PROMPT_V2 §7 plus 8 and 9', function (): void {
    expect(array_map(static fn (ExitCode $c): int => $c->value, ExitCode::cases()))->toBe([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10])
        ->and(ExitCode::PreflightFailed->isBeforeChange())->toBeTrue()
        ->and(ExitCode::HealthCheckFailed->isBeforeChange())->toBeFalse()
        ->and(ResultStatus::RolledBack->closesOperation())->toBeTrue()
        ->and(ResultStatus::RollbackFailed->closesOperation())->toBeFalse();
});

it('changes only through immutable withers', function (): void {
    $base = CommandResult::ok('install', ['url' => 'http://localhost:8080']);

    $next = $base->withData(['version' => '2.0.0'])->withChanged(true)->withWarning('w', 'warn', 'hint')->withError('e', 'err', retryable: true)->withExitCode(ExitCode::HealthCheckFailed);

    expect($base->data)->toBe(['url' => 'http://localhost:8080'])
        ->and($base->changed)->toBeFalse()
        ->and($next->data)->toBe(['url' => 'http://localhost:8080', 'version' => '2.0.0'])
        ->and($next->toArray()['warnings'])->toBe([['code' => 'w', 'message' => 'warn', 'hint' => 'hint']])
        ->and($next->toArray()['errors'])->toBe([['code' => 'e', 'message' => 'err', 'hint' => null, 'retryable' => true]])
        ->and($next->status())->toBe(ResultStatus::Failed);
});

it('builds a failure from an instance exception', function (): void {
    $result = CommandResult::fromException('restore', new InstanceException('newer backup', ExitCode::Incompatible, 'backup_newer', 'Upgrade first', false, ['backup' => '2.1.0']), changed: false);

    expect($result->exitCode)->toBe(ExitCode::Incompatible)
        ->and($result->data)->toBe(['backup' => '2.1.0'])
        ->and($result->toArray()['errors'][0])->toBe(['code' => 'backup_newer', 'message' => 'newer backup', 'hint' => 'Upgrade first', 'retryable' => false]);
});

it('shows secret values only under secrets and scrubs them everywhere else', function (): void {
    $secret = new Secret('sign_in_link', 'https://x.test/#token-abcdef', CarbonImmutable::parse('2026-10-17 10:15:00', 'UTC'));

    $envelope = CommandResult::ok('login-link', ['nested' => ['link' => 'https://x.test/#token-abcdef'], 'count' => 1])
        ->withSecret($secret)
        ->withError('e', 'failed for token-abcdef', 'retry https://x.test/#token-abcdef')
        ->toArray();

    expect($envelope['secrets'])->toBe([['name' => 'sign_in_link', 'value' => 'https://x.test/#token-abcdef', 'expires_at' => '2026-10-17T10:15:00Z']])
        ->and($envelope['data'])->toBe(['nested' => ['link' => '[redacted]'], 'count' => 1])
        ->and($envelope['errors'][0]['message'])->toBe('failed for token-abcdef')
        ->and($envelope['errors'][0]['hint'])->toBe('retry [redacted]');
});

it('names the secrets file instead of the values when one is given', function (): void {
    $json = CommandResult::ok('demo')->withSecret(new Secret('demo_password', 'pw-secret-1'))->toJson(null, '/home/a/secrets.json');

    expect($json)->toContain('"written_to":"/home/a/secrets.json"')->not->toContain('pw-secret-1');
});

it('keeps secret values out of debug output', function (): void {
    expect(print_r(new Secret('x', 'pw-secret-1'), true))->not->toContain('pw-secret-1');
});

it('redacts longest secrets first, ignores very short values and leaves non-strings alone', function (): void {
    $redactor = new SecretRedactor;
    $redactor->register('abcd');
    $redactor->register('abcdefgh');
    $redactor->register('xy');

    expect($redactor->redact(['a' => 'abcdefgh-abcd-xy', 'n' => 5, 'b' => true, 'l' => ['abcd']]))
        ->toBe(['a' => '[redacted]-[redacted]-xy', 'n' => 5, 'b' => true, 'l' => ['[redacted]']])
        ->and((new SecretRedactor)->redactString('plain'))->toBe('plain');
});

it('streams NDJSON progress events with --json and readable lines otherwise', function (): void {
    CarbonImmutable::setTestNow('2026-10-17 10:00:00');
    $json = new BufferedOutput;
    $human = new BufferedOutput;
    $redactor = new SecretRedactor;
    $redactor->register('s3cret-value');

    $reporter = new ProgressReporter($json, true, $redactor);
    $reporter->phase('pull', 'Pulling 6 images');
    $reporter->warn('pull', 'slow registry');
    $reporter->error('up', 'failed with s3cret-value');
    $text = new ProgressReporter($human, false, $redactor);
    $text->phase('pull', 'Pulling');
    $text->warn('pull', 'slow');
    $text->error('up', 'down s3cret-value');

    $events = array_map(static fn (string $line): array => json_decode($line, true), explode("\n", trim($json->fetch())));
    expect($events)->toBe([
        ['ts' => '2026-10-17T10:00:00Z', 'phase' => 'pull', 'level' => 'info', 'message' => 'Pulling 6 images'],
        ['ts' => '2026-10-17T10:00:00Z', 'phase' => 'pull', 'level' => 'warn', 'message' => 'slow registry'],
        ['ts' => '2026-10-17T10:00:00Z', 'phase' => 'up', 'level' => 'error', 'message' => 'failed with [redacted]'],
    ])->and($human->fetch())->toBe("› Pulling\n! slow\n✗ down [redacted]\n");
});

it('renders warnings and errors as messages', function (): void {
    expect(Message::warning('w', 'm')->toArray())->toBe(['code' => 'w', 'message' => 'm', 'hint' => null])
        ->and(Message::error('e', 'm', 'h', true)->toArray())->toBe(['code' => 'e', 'message' => 'm', 'hint' => 'h', 'retryable' => true]);
});
