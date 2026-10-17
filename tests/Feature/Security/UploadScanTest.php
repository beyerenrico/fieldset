<?php

declare(strict_types=1);

use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Support\ClamdUploadScanner;
use App\Domain\Submissions\Support\ConfiguredUploadScanner;
use App\Domain\Submissions\Support\UploadInspector;
use App\Domain\Submissions\Support\UploadScanFailed;
use App\Domain\Submissions\Support\UploadScanner;
use App\Domain\Submissions\Support\UploadScanResult;
use App\Http\Api\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

/* Optional ClamAV hook (BUILD_PROMPT §11, ADR 0040): scanner interface, null default, clamd INSTREAM client. */

/** A scanner that records what it was given and answers with a fixed verdict (or throws). */
function fakeUploadScanner(UploadScanResult|UploadScanFailed $verdict): UploadScanner
{
    return new class($verdict) implements UploadScanner
    {
        /** @var list<string> */
        public array $scanned = [];

        public function __construct(private readonly UploadScanResult|UploadScanFailed $verdict) {}

        public function scan(string $bytes): UploadScanResult
        {
            $this->scanned[] = $bytes;

            return $this->verdict instanceof UploadScanFailed ? throw $this->verdict : $this->verdict;
        }
    };
}

/**
 * Runs the clamd client against an in-process socket pair whose "daemon" end already holds the reply.
 *
 * @return array{0: UploadScanResult|UploadScanFailed, 1: string} verdict (or failure) and the bytes clamd received
 */
function clamdExchange(string $payload, ?string $reply, int $chunkBytes = 4): array
{
    [$client, $daemon] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($reply !== null) {
        fwrite($daemon, $reply);
    }
    $scanner = new ClamdUploadScanner('clamav', 3310, 0.2, $chunkBytes, fn (): mixed => $client);
    try {
        $verdict = $scanner->scan($payload);
    } catch (UploadScanFailed $e) {
        $verdict = $e;
    }
    stream_set_blocking($daemon, false);
    $received = (string) stream_get_contents($daemon);
    fclose($daemon);

    return [$verdict, $received];
}

it('does not scan by default', function (): void {
    expect(app(UploadScanner::class))->toBeInstanceOf(ConfiguredUploadScanner::class)
        ->and(app(UploadScanner::class)->scan('bytes')->scanned)->toBeFalse()
        ->and(app(UploadScanner::class)->scan('bytes')->clean)->toBeTrue();
});

it('asks clamd when enabled and fails closed when it is unreachable', function (): void {
    config(['uploads.clamav' => ['enabled' => true, 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.5]]);

    expect(fn () => app(UploadScanner::class)->scan('bytes'))->toThrow(UploadScanFailed::class, 'clamd is not reachable at 127.0.0.1:1.');
});

it('speaks the clamd INSTREAM protocol in length-prefixed chunks', function (): void {
    [$verdict, $received] = clamdExchange('0123456789', "stream: OK\0");

    expect($verdict)->toEqual(UploadScanResult::clean())
        ->and($received)->toBe("zINSTREAM\0".pack('N', 4).'0123'.pack('N', 4).'4567'.pack('N', 2).'89'.pack('N', 0));
});

it('reports the signature clamd found', function (): void {
    [$verdict] = clamdExchange('X5O!P%@AP', "stream: Eicar-Test-Signature FOUND\0");

    expect($verdict)->toEqual(UploadScanResult::infected('Eicar-Test-Signature'));
});

it('treats clamd errors and silence as no verdict', function (?string $reply, string $message, bool $tooLarge): void {
    [$verdict] = clamdExchange('data', $reply);

    expect($verdict)->toBeInstanceOf(UploadScanFailed::class)
        ->and($verdict->getMessage())->toBe($message)
        ->and($verdict->tooLarge)->toBe($tooLarge);
})->with([
    'size limit' => ["INSTREAM size limit exceeded. ERROR\0", 'clamd answered: INSTREAM size limit exceeded. ERROR', true],
    'other error' => ["Command read timed out. ERROR\0", 'clamd answered: Command read timed out. ERROR', false],
    'timeout' => [null, 'clamd did not answer in time.', false],
]);

it('reads the size-limit reply when clamd hangs up in the middle of the stream', function (): void {
    $file = storage_path('framework/testing/clamd-reply-'.uniqid());
    @mkdir(dirname($file), 0775, true);
    file_put_contents($file, "INSTREAM size limit exceeded. ERROR\0");
    // A read-only stream: every write fails like a connection clamd has closed, its reply is still readable.
    $scanner = new ClamdUploadScanner('clamav', 3310, 0.2, 4, fn (): mixed => fopen($file, 'r'));

    try {
        expect(fn () => $scanner->scan('0123456789'))->toThrow(function (UploadScanFailed $e): void {
            expect($e->tooLarge)->toBeTrue()->and($e->getMessage())->toContain('INSTREAM size limit exceeded');
        });
    } finally {
        @unlink($file);
    }
});

it('refuses a file above the scanner limit as too large, also when failing open', function (): void {
    config(['uploads.clamav.fail_open' => true]);
    $inspector = new UploadInspector(fakeUploadScanner(new UploadScanFailed('clamd answered: INSTREAM size limit exceeded. ERROR', tooLarge: true)));

    try {
        $inspector->inspect('plain text', 'file', 1024);
        $this->fail('The upload was accepted unscanned.');
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe('file_too_large')->and($e->status)->toBe(422);
    }
});

it('scans the original bytes after the type check and before re-encoding', function (): void {
    $scanner = fakeUploadScanner(UploadScanResult::clean());
    $png = FieldAppScenario::png();

    $out = new UploadInspector($scanner)->inspect($png, 'signature', 1024 * 1024);

    expect($scanner->scanned)->toBe([$png])->and($out['mime'])->toBe('image/png');
    expect(fn () => new UploadInspector($scanner)->inspect('<?php echo 1;', 'file', 1024))->toThrow(ApiException::class);
    expect($scanner->scanned)->toHaveCount(1);
});

it('rejects an infected file with 422 and logs the signature without the content', function (): void {
    Log::spy();
    $inspector = new UploadInspector(fakeUploadScanner(UploadScanResult::infected('Eicar-Test-Signature')));

    try {
        $inspector->inspect('%PDF-1.4 fake', 'file', 1024 * 1024);
        $this->fail('The upload was accepted.');
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe('file_infected')->and($e->status)->toBe(422);
    }

    Log::shouldHaveReceived('warning')->once()->with('upload.infected', Mockery::on(
        fn (array $context): bool => $context['signature'] === 'Eicar-Test-Signature' && ! in_array('%PDF-1.4 fake', $context, true),
    ));
});

it('answers 503 without a verdict unless configured to fail open', function (): void {
    $inspector = new UploadInspector(fakeUploadScanner(new UploadScanFailed('down')));

    try {
        $inspector->inspect('plain text', 'file', 1024);
        $this->fail('The upload was accepted.');
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe('scanner_unavailable')->and($e->status)->toBe(503);
    }

    config(['uploads.clamav.fail_open' => true]);
    expect($inspector->inspect('plain text', 'file', 1024)['mime'])->toBe('text/plain');
});

it('refuses an infected upload through the API without storing anything', function (): void {
    Storage::fake('local');
    $this->app->instance(UploadScanner::class, fakeUploadScanner(UploadScanResult::infected('Eicar-Test-Signature')));
    $s = FieldAppScenario::make();
    $uuid = FieldAppScenario::uuid();
    FieldAppApi::put($s, $s->worker, $uuid, ['customer_name' => 'A'])->assertCreated();

    $this->withHeaders(apiHeaders($s->worker, $s->org))->post("/api/v1/submissions/{$uuid}/attachments", [
        'file' => UploadedFile::fake()->createWithContent('s.png', FieldAppScenario::png()),
        'attachment_uuid' => FieldAppScenario::uuid(), 'kind' => 'signature', 'element_key' => 'customer_signature',
    ])->assertStatus(422)->assertJsonPath('error.code', 'file_infected');

    expect(Attachment::query()->withoutTenant()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
