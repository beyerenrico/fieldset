<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Instance\RuntimeFixture;
use Tests\Support\Jobs\RecordRuntimeUrlJob;
use Tests\Support\Notifications\RuntimeLinkNotification;

require_once __DIR__.'/Support.php';

/*
 * Plan U10 / R56: a JobProcessing listener re-reads the runtime before every job, so queued jobs, notifications and
 * mails link to the instance URL (or the active tunnel) of the moment the job starts, without a worker restart.
 */

/** Processes the database queue in this process, as a worker would (per-job scope reset included). */
function runRuntimeWorker(): void
{
    app(TenantContext::class)->clear();
    Context::flush();
    app()->forgetScopedInstances();
    Artisan::call('queue:work', ['connection' => 'database', '--max-jobs' => 5, '--stop-when-empty' => true, '--tries' => 1, '--memory' => 4096]);
}

/** @return list<string> HTML bodies of the mails sent through the array transport */
function runtimeMailBodies(): array
{
    return app('mailer')->getSymfonyTransport()->messages()
        ->map(function (SentMessage $message): string {
            $email = $message->getOriginalMessage();

            return $email instanceof Email ? (string) $email->getHtmlBody() : '';
        })->values()->all();
}

beforeEach(function (): void {
    config(['queue.default' => 'database', 'mail.default' => 'array']);
    RecordRuntimeUrlJob::$seen = [];
});

afterEach(function (): void {
    RuntimeFixture::cleanup();
});

it('links jobs to the instance URL of the moment they start, without a worker restart', function (): void {
    $directory = RuntimeFixture::install(RuntimeFixture::domain('one.example'));

    RecordRuntimeUrlJob::dispatch();
    runRuntimeWorker();
    RuntimeFixture::write($directory, RuntimeFixture::document(RuntimeFixture::domain('two.example')));
    RecordRuntimeUrlJob::dispatch();
    runRuntimeWorker();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(array_column(RecordRuntimeUrlJob::$seen, 'url'))->toBe(['https://one.example/admin', 'https://two.example/admin'])
        // APP_URL stays as rendered: Message-IDs take their host from it and must be stable across attempts.
        ->and(array_column(RecordRuntimeUrlJob::$seen, 'app_url'))->toBe(['http://localhost:8080', 'http://localhost:8080'])
        ->and(RecordRuntimeUrlJob::$seen[1]['signed'])->toStartWith('https://two.example/app?signature=');
});

it('sends queued notifications with links to the current instance URL', function (): void {
    $directory = RuntimeFixture::install(RuntimeFixture::domain('one.example'));
    RuntimeFixture::write($directory, RuntimeFixture::document(RuntimeFixture::domain('two.example')));

    Notification::route('mail', 'crew@example.com')->notify(new RuntimeLinkNotification);
    expect(runtimeMailBodies())->toBe([]);
    runRuntimeWorker();

    expect(runtimeMailBodies())->toHaveCount(1)
        ->and(runtimeMailBodies()[0])->toContain('https://two.example/app/tasks')->not->toContain('localhost:8080/app');
});

it('links a localhost request to localhost while a job started during the tunnel links to the tunnel', function (): void {
    registerRuntimeProbes();
    RuntimeFixture::install(RuntimeFixture::tunnel());

    runtimeRequest('http://localhost:8080/api/_runtime-probe')->assertOk()->assertJson(['url' => 'http://localhost:8080/admin']);
    RecordRuntimeUrlJob::dispatch();
    runRuntimeWorker();

    expect(RecordRuntimeUrlJob::$seen[0]['url'])->toBe('https://'.RuntimeFixture::TUNNEL.'/admin');
});

it('falls back to the instance URL for jobs once the tunnel has expired', function (): void {
    RuntimeFixture::install(RuntimeFixture::tunnel('-1 second'));

    RecordRuntimeUrlJob::dispatch();
    runRuntimeWorker();

    expect(RecordRuntimeUrlJob::$seen[0]['url'])->toBe('http://localhost:8080/admin');
});

it('leaves jobs on APP_URL without the runtime directory', function (): void {
    config(['instance.runtime_path' => null, 'app.url' => 'http://localhost:8080']);

    RecordRuntimeUrlJob::dispatch();
    runRuntimeWorker();

    expect(RecordRuntimeUrlJob::$seen[0])->toMatchArray(['url' => 'http://localhost:8080/admin', 'app_url' => 'http://localhost:8080']);
});

it('keeps the request\'s own links for a sync job inside a request', function (): void {
    config(['queue.default' => 'sync']);
    RuntimeFixture::install(RuntimeFixture::tunnel());
    Route::middleware('web')->get('api/_runtime-sync-job', function (Request $request): string {
        RecordRuntimeUrlJob::dispatch();

        return url('/admin');
    });

    runtimeRequest('http://localhost:8080/api/_runtime-sync-job')->assertOk()->assertSee('http://localhost:8080/admin');

    expect(RecordRuntimeUrlJob::$seen[0]['url'])->toBe('http://localhost:8080/admin');
});
