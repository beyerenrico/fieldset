<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Support\Logging\ExceptionMessage;
use App\Support\Logging\JsonLogFormatter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\StreamHandler;

/* Structured JSON logs (BUILD_PROMPT §11 Operability, ADR 0040): formatter, request id, redaction. */

beforeEach(function (): void {
    $this->logFile = storage_path('framework/testing/json-'.uniqid().'.log');
    @mkdir(dirname($this->logFile), 0775, true);
});

afterEach(function (): void {
    @unlink($this->logFile);
});

/** @return list<array<string, mixed>> the JSON lines written to the file */
function jsonLogLines(string $file): array
{
    $lines = array_filter(explode("\n", (string) @file_get_contents($file)));

    return array_values(array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
}

it('works with the stock stderr channel through LOG_STDERR_FORMATTER', function (): void {
    config([
        'logging.channels.stderr.formatter' => JsonLogFormatter::class,
        'logging.channels.stderr.handler_with' => ['stream' => $this->logFile],
    ]);
    Context::add('request_id', 'req-12345678');

    Log::channel('stderr')->warning('Payment {status}', ['status' => 'failed', 'password' => 'hunter2', 'nested' => ['api_key' => 'k']]);

    $line = jsonLogLines($this->logFile)[0];
    expect($line)->toMatchArray([
        'level' => 'warning',
        'message' => 'Payment failed',
        'request_id' => 'req-12345678',
        'service' => config('app.name'),
        'environment' => 'testing',
    ])->and($line['@timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/')
        ->and($line['context']['password'])->toBe('[redacted]')
        ->and($line['context']['nested']['api_key'])->toBe('[redacted]')
        ->and($line)->not->toHaveKey('extra');
});

it('writes one line per record with the exception and its stack trace', function (): void {
    config(['logging.channels.json-test' => [
        'driver' => 'monolog', 'handler' => StreamHandler::class, 'handler_with' => ['stream' => $this->logFile],
        'formatter' => JsonLogFormatter::class,
    ]]);

    Log::channel('json-test')->error('Render failed', ['exception' => new RuntimeException('Gotenberg down'), 'headers' => ['Authorization' => 'Bearer x', 'Cookie' => 'c']]);
    Log::channel('json-test')->info('second');

    $lines = jsonLogLines($this->logFile);
    $exception = $lines[0]['context']['exception'];
    expect($lines)->toHaveCount(2)
        ->and($exception['class'])->toBe(RuntimeException::class)
        ->and($exception['message'])->toBe('Gotenberg down')
        ->and($exception['file'])->toStartWith('tests/Feature/Security/StructuredLoggingTest.php:')
        ->and($exception['trace'])->toBeArray()->not->toBeEmpty()
        ->and($lines[0]['context']['headers'])->toBe(['Authorization' => '[redacted]', 'Cookie' => '[redacted]'])
        ->and($lines[1]['message'])->toBe('second');
});

it('carries the request id assigned to an HTTP request', function (): void {
    config(['logging.channels.json-test' => [
        'driver' => 'monolog', 'handler' => StreamHandler::class, 'handler_with' => ['stream' => $this->logFile],
        'formatter' => JsonLogFormatter::class,
    ]]);
    Route::get('/log-probe', function (): string {
        Log::channel('json-test')->info('inside');

        return 'ok';
    });

    $this->get('/log-probe', ['X-Request-Id' => 'abcdef123456'])->assertOk()->assertHeader('X-Request-Id', 'abcdef123456');

    expect(jsonLogLines($this->logFile)[0]['request_id'])->toBe('abcdef123456');
});

it('logs database errors without bound values or DETAIL and URLs without query strings', function (): void {
    config(['logging.channels.json-test' => [
        'driver' => 'monolog', 'handler' => StreamHandler::class, 'handler_with' => ['stream' => $this->logFile],
        'formatter' => JsonLogFormatter::class,
    ]]);
    $pdo = new PDOException("SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\nDETAIL:  Key (email)=(victim@example.com) already exists.");
    $query = new QueryException('pgsql', 'insert into users (email, password) values (?, ?)', ['victim@example.com', '$2y$12$secrethash'], $pdo);
    $http = new RuntimeException('cURL error 7: Failed to connect for https://hooks.example/in?token=abc123&sig=s', 0, $query);

    Log::channel('json-test')->error('Webhook failed', ['exception' => $http, 'endpoint' => new class
    {
        public string $url = 'https://hooks.example/in';

        public string $token = 'abc123';
    }]);

    $raw = (string) file_get_contents($this->logFile);
    $exception = jsonLogLines($this->logFile)[0]['context']['exception'];
    expect($exception['message'])->toBe('cURL error 7: Failed to connect for https://hooks.example/in?[redacted]')
        ->and($exception['previous']['message'])->toBe('SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "users_email_unique" (SQL: insert into users (email, password) values (?, ?))')
        ->and($raw)->not->toContain('victim@example.com')->not->toContain('secrethash')->not->toContain('abc123');
});

it('logs reported exceptions with a safe top-level message: no DETAIL, bound values, database name or URL tokens', function (): void {
    config([
        'logging.channels.json-test' => [
            'driver' => 'monolog', 'handler' => StreamHandler::class, 'handler_with' => ['stream' => $this->logFile],
            'formatter' => JsonLogFormatter::class,
        ],
        'logging.default' => 'json-test',
    ]);
    User::factory()->create(['email' => 'victim@example.com']);
    $query = null;
    try {
        DB::transaction(fn () => User::factory()->create(['email' => 'victim@example.com', 'password' => 'Unique-Secret-Passw0rd']));
    } catch (QueryException $caught) {
        $query = $caught;
    }
    expect($query)->toBeInstanceOf(QueryException::class)
        ->and($query->getMessage())->toContain('victim@example.com')->toContain((string) config('database.connections.pgsql.database'));

    report($query);
    report(new RuntimeException('Webhook https://hooks.example/in?token=abc123&sig=s answered 500'));

    $raw = (string) file_get_contents($this->logFile);
    $lines = jsonLogLines($this->logFile);
    expect($lines)->toHaveCount(2)
        ->and($lines[0]['message'])->toBe(ExceptionMessage::safe($query))
        ->and($lines[0]['message'])->toStartWith('SQLSTATE[23505]')->toContain('(SQL: insert into')
        ->and($lines[1]['message'])->toBe('Webhook https://hooks.example/in?[redacted] answered 500')
        ->and($raw)->not->toContain('victim@example.com')->not->toContain('DETAIL')
        ->not->toContain('Database: '.config('database.connections.pgsql.database'))->not->toContain('abc123');
});

it('scrubs log messages without an exception and keeps a custom message next to an exception', function (): void {
    config(['logging.channels.json-test' => [
        'driver' => 'monolog', 'handler' => StreamHandler::class, 'handler_with' => ['stream' => $this->logFile],
        'formatter' => JsonLogFormatter::class,
    ]]);
    $e = new RuntimeException('Gotenberg at https://user:pw@gotenberg.internal/forms?token=t0k answered 503');

    Log::channel('json-test')->warning('Calling https://hooks.example/in?token=abc123 with password=hunter2');
    Log::channel('json-test')->error('Render failed: '.$e->getMessage(), ['exception' => $e]);
    Log::channel('json-test')->error('Render failed', ['exception' => $e]);

    $lines = jsonLogLines($this->logFile);
    expect($lines[0]['message'])->toBe('Calling https://hooks.example/in?[redacted] with password=[redacted]')
        ->and($lines[1]['message'])->toBe('Render failed: '.ExceptionMessage::safe($e))
        ->and($lines[2]['message'])->toBe('Render failed')
        ->and((string) file_get_contents($this->logFile))->not->toContain('hunter2')->not->toContain('t0k')->not->toContain(':pw@');
});
