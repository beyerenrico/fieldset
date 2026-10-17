<?php

declare(strict_types=1);

use App\Support\Logging\CreateSentryLogger;
use App\Support\Logging\SentryDsn;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/* Optional Sentry-compatible reporting without an SDK (BUILD_PROMPT §11, ADR 0040). */

const SENTRY_TEST_DSN = 'https://public123@errors.example:8443/glitchtip/42';

beforeEach(function (): void {
    config(['logging.channels.sentry-test' => [
        'driver' => 'custom', 'via' => CreateSentryLogger::class, 'dsn' => SENTRY_TEST_DSN,
        'level' => 'error', 'environment' => 'production', 'release' => '1.0.0',
    ]]);
});

/** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} envelope header, item header, event */
function sentryEnvelope(Request $request): array
{
    $lines = explode("\n", trim($request->body()));

    return array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), array_slice($lines, 0, 3));
}

it('parses DSNs of Sentry and self-hosted compatible servers', function (): void {
    $dsn = SentryDsn::tryParse(SENTRY_TEST_DSN);

    expect($dsn?->publicKey)->toBe('public123')
        ->and($dsn?->envelopeUrl)->toBe('https://errors.example:8443/glitchtip/api/42/envelope/')
        ->and(SentryDsn::tryParse('https://key@o1.ingest.sentry.io/7')?->envelopeUrl)->toBe('https://o1.ingest.sentry.io/api/7/envelope/')
        ->and(SentryDsn::tryParse(''))->toBeNull()
        ->and(SentryDsn::tryParse(null))->toBeNull()
        ->and(SentryDsn::tryParse('https://errors.example/42'))->toBeNull()
        ->and(SentryDsn::tryParse('ftp://k@errors.example/42'))->toBeNull()
        ->and(SentryDsn::tryParse('https://k@errors.example/project'))->toBeNull();
});

it('reports an exception as an envelope with its chain, frames and request id but no secrets', function (): void {
    Http::fake(['errors.example:8443/*' => Http::response(['id' => 'x'])]);
    Context::add('request_id', 'req-87654321');
    $exception = new RuntimeException('Render failed', 0, new LogicException('Gotenberg down'));

    Log::channel('sentry-test')->error('Render failed', ['exception' => $exception, 'userId' => 7, 'password' => 'hunter2']);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        [$header, $item, $event] = sentryEnvelope($request);
        $frames = $event['exception']['values'][1]['stacktrace']['frames'];

        expect($request->url())->toBe('https://errors.example:8443/glitchtip/api/42/envelope/')
            ->and($request->header('X-Sentry-Auth')[0])->toBe('Sentry sentry_version=7, sentry_client=form-schema-app/1.0, sentry_key=public123')
            ->and($request->header('Content-Type')[0])->toBe('application/x-sentry-envelope')
            ->and($header['event_id'])->toBe($event['event_id'])->toMatch('/^[0-9a-f]{32}$/')
            ->and($item['type'])->toBe('event')
            ->and($event)->toMatchArray(['platform' => 'php', 'level' => 'error', 'environment' => 'production', 'release' => '1.0.0'])
            ->and($event['tags'])->toBe(['request_id' => 'req-87654321'])
            ->and($event['user'])->toBe(['id' => '7'])
            ->and($event['extra']['password'])->toBe('[redacted]')
            ->and(array_column($event['exception']['values'], 'type'))->toBe([LogicException::class, RuntimeException::class])
            ->and(end($frames)['filename'])->toBe('tests/Feature/Security/SentryReportingTest.php')
            ->and(end($frames)['in_app'])->toBeTrue()
            ->and($request->body())->not->toContain('hunter2');

        return true;
    });
});

it('reports database errors and objects without bound values or secrets', function (): void {
    Http::fake();
    $pdo = new PDOException("SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key\nDETAIL:  Key (email)=(victim@example.com) already exists.");
    $query = new QueryException('pgsql', 'update users set password = ? where id = ?', ['$2y$12$secrethash', 7], $pdo);

    Log::channel('sentry-test')->error('Save failed', ['exception' => $query, 'payload' => new ArrayObject(['api_key' => 'k-123', 'note' => 'ok'])]);

    Http::assertSent(function (Request $request): bool {
        $event = sentryEnvelope($request)[2];

        expect(array_column($event['exception']['values'], 'value'))->toBe([
            'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key',
            'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key (SQL: update users set password = [redacted] where id = ?)',
        ])->and($event['extra']['payload'])->toBe(['api_key' => '[redacted]', 'note' => 'ok'])
            ->and($request->body())->not->toContain('victim@example.com')->not->toContain('secrethash')->not->toContain('k-123');

        return true;
    });
});

it('sends plain messages at or above the level only', function (): void {
    Http::fake();

    Log::channel('sentry-test')->warning('ignored');
    Log::channel('sentry-test')->critical('Queue stuck');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => sentryEnvelope($request)[2]['message'] === ['formatted' => 'Queue stuck']
        && sentryEnvelope($request)[2]['level'] === 'fatal');
});

it('never breaks the request when the server is unreachable', function (): void {
    $errorLog = storage_path('framework/testing/sentry-error.log');
    @unlink($errorLog);
    $previous = ini_set('error_log', $errorLog);
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    try {
        Log::channel('sentry-test')->error('boom');
    } finally {
        ini_set('error_log', (string) $previous);
    }

    expect((string) file_get_contents($errorLog))->toContain('Sentry-compatible reporting failed: Connection refused');
    @unlink($errorLog);
});

it('discards everything without a DSN', function (): void {
    Http::fake();
    config(['logging.channels.sentry-test.dsn' => null]);

    Log::channel('sentry-test')->emergency('nobody listens');

    Http::assertNothingSent();
});
