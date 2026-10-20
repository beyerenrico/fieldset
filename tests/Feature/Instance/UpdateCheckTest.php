<?php

declare(strict_types=1);

use App\Domain\Instance\Runtime\RuntimeDocument;
use App\Domain\Platform\Instance\Updates\UpdateCheck;
use App\Domain\Platform\Instance\Updates\UpdateStatus;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\Instance\RuntimeFixture;

require_once __DIR__.'/../Platform/Support.php';

/*
 * The opt-in update check (E15, R48, plan U20, ADR 0061, ADR 0089): off by default, and off it sends nothing at all. On,
 * the daily run reads only the release manifest URL from runtime.json, with no instance identifier, and the instance page
 * shows "update available" with the notes link (https only) and `fsa upgrade`.
 */

const UPDATE_CHECK_URL = 'https://github.com/beyerenrico/form-schema-app/releases/latest/download/release-manifest.json';

function updateCheckOn(string $url = UPDATE_CHECK_URL): void
{
    RuntimeFixture::install(['updates' => ['check' => true, 'manifest_url' => $url]]);
}

/** @param  array<string, mixed>  $manifest */
function updateCheckManifest(array $manifest = []): string
{
    return (string) json_encode(['schema_version' => 1, 'version' => '2.1.0', 'channel' => 'stable', 'notes_url' => 'https://github.com/beyerenrico/form-schema-app/releases/tag/v2.1.0', ...$manifest]);
}

function updateCheckScheduled(): Event
{
    $event = collect(app(Schedule::class)->events())->first(static fn (Event $event): bool => str_contains((string) $event->command, 'instance:check-updates'));
    expect($event)->toBeInstanceOf(Event::class);

    return $event;
}

beforeEach(function (): void {
    config(['app.version' => '2.0.0']);
    Http::preventStrayRequests();
});

afterEach(fn () => RuntimeFixture::cleanup());

it('sends nothing while the check is off: no runtime file, the check off, or a malformed updates block', function (?array $updates): void {
    if ($updates !== null) {
        RuntimeFixture::install(['updates' => $updates]);
    }
    Http::fake();

    $status = app(UpdateCheck::class)->run();
    $this->artisan('instance:check-updates')->expectsOutputToContain('off')->assertSuccessful();

    expect($status)->toBeNull()->and(app(UpdateCheck::class)->enabled())->toBeFalse()
        ->and(updateCheckScheduled()->filtersPass(app()))->toBeFalse()
        ->and(Cache::get(UpdateCheck::CACHE_KEY))->toBeNull();
    Http::assertNothingSent();
})->with([
    'not an fsa instance' => [null],
    'check off' => [['check' => false, 'manifest_url' => null]],
    'check off with a URL left' => [['check' => false, 'manifest_url' => UPDATE_CHECK_URL]],
    'check as a string' => [['check' => 'yes', 'manifest_url' => UPDATE_CHECK_URL]],
    'no URL' => [['check' => true, 'manifest_url' => null]],
    'not a URL' => [['check' => true, 'manifest_url' => 'javascript:alert(1)']],
    'URL with credentials' => [['check' => true, 'manifest_url' => 'https://user:pw@example.com/m.json']],
    'URL with a query' => [['check' => true, 'manifest_url' => 'https://example.com/m.json?instance=1']],
    'block not an object' => [['on']],
]);

it('reads only the manifest URL once and shows update available with the notes link and the upgrade command', function (): void {
    updateCheckOn();
    Http::fake([UPDATE_CHECK_URL => Http::response(updateCheckManifest())]);

    $this->artisan('instance:check-updates')->expectsOutputToContain('2.1.0')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET' && $request->url() === UPDATE_CHECK_URL && $request->body() === ''
        && ! $request->hasHeader('Cookie') && ! $request->hasHeader('Authorization'));
    expect(updateCheckScheduled()->filtersPass(app()))->toBeTrue()
        ->and(app(UpdateCheck::class)->last())->toBeInstanceOf(UpdateStatus::class)
        ->and(app(UpdateCheck::class)->last()?->availableFor('2.0.0'))->toBeTrue();

    actingAsPlatformOperator(platformOperator());
    $this->get('/platform/instance')->assertOk()
        ->assertSee('data-instance-problem="update_available"', false)->assertSee(__('instance.problems.update_available', ['version' => '2.1.0']))
        ->assertSee('data-instance-updates="available"', false)->assertSee('fsa upgrade')
        ->assertSee('href="https://github.com/beyerenrico/form-schema-app/releases/tag/v2.1.0"', false);
    Http::assertSentCount(1); // the page itself never calls out
});

it('shows no update when the newest release is the running one or older', function (string $latest): void {
    updateCheckOn();
    Http::fake([UPDATE_CHECK_URL => Http::response(updateCheckManifest(['version' => $latest]))]);

    $status = app(UpdateCheck::class)->run();

    expect($status?->latest)->toBe($latest)->and($status?->availableFor('2.0.0'))->toBeFalse()->and($status?->error)->toBeNull();
    actingAsPlatformOperator(platformOperator());
    $this->get('/platform/instance')->assertOk()->assertDontSee('data-instance-problem="update_available"', false)
        ->assertSee(__('instance.updates.current', ['version' => '2.0.0']));
})->with(['same' => '2.0.0', 'older' => '1.9.9', 'pre-release of the running one' => '2.0.0-rc.1']);

it('does not make a notes URL that is not https a link', function (): void {
    updateCheckOn();
    Http::fake([UPDATE_CHECK_URL => Http::response(updateCheckManifest(['notes_url' => 'http://example.com/notes']))]);

    $status = app(UpdateCheck::class)->run();

    expect($status?->notesUrl)->toBe('http://example.com/notes')->and($status?->notesLink())->toBeNull();
    actingAsPlatformOperator(platformOperator());
    $this->get('/platform/instance')->assertOk()->assertSee('data-instance-notes-text', false)->assertDontSee('href="http://example.com/notes"', false);
});

it('records a failed check and keeps the newest release seen before', function (Closure $answer, string $error): void {
    updateCheckOn();
    Cache::forever(UpdateCheck::CACHE_KEY, ['checked_at' => now()->subDay()->toIso8601ZuluString(), 'latest' => '2.1.0', 'notes_url' => 'https://example.com/n', 'error' => null]);
    Http::fake([UPDATE_CHECK_URL => $answer]);

    $status = app(UpdateCheck::class)->run();

    expect($status?->error)->toBe($error)->and($status?->latest)->toBe('2.1.0')->and($status?->notesUrl)->toBe('https://example.com/n')
        ->and(app(UpdateCheck::class)->last()?->error)->toBe($error)
        ->and(app(UpdateCheck::class)->last()?->checkedAt->isToday())->toBeTrue();
})->with([
    'server error' => [fn () => Http::response('down', 503), 'http_503'],
    'not found' => [fn () => Http::response('', 404), 'http_404'],
    'unreachable' => [fn () => Http::failedConnection(), 'unreachable'],
    'not JSON' => [fn () => Http::response('<html>'), 'invalid_manifest'],
    'no version' => [fn () => Http::response(['notes_url' => 'https://x.test']), 'invalid_manifest'],
    'version not semantic' => [fn () => Http::response(['version' => 'latest; rm -rf /']), 'invalid_manifest'],
    'JSON list' => [fn () => Http::response('[1,2]'), 'invalid_manifest'],
    'empty body' => [fn () => Http::response(''), 'invalid_manifest'],
    'larger than a manifest' => [fn () => Http::response(str_repeat(' ', 1_048_577)), 'invalid_manifest'],
]);

it('reads a harness mirror over http when runtime.json names one', function (): void {
    updateCheckOn('http://releases.fsa-test.internal:8080/latest/download/release-manifest.json');
    Http::fake(['releases.fsa-test.internal:8080/*' => Http::response(updateCheckManifest())]);

    expect(app(UpdateCheck::class)->run()?->latest)->toBe('2.1.0');
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'http://releases.fsa-test.internal:8080/latest/download/release-manifest.json');
});

it('ignores a stored status that does not look like one', function (mixed $stored): void {
    updateCheckOn();
    Cache::forever(UpdateCheck::CACHE_KEY, $stored);

    expect(app(UpdateCheck::class)->last())->toBeNull();
})->with([
    'string' => ['2.1.0'],
    'no time' => [['latest' => '2.1.0']],
    'bad version' => [['checked_at' => '2026-10-20T00:00:00Z', 'latest' => '<b>2</b>']],
    'bad time' => [['checked_at' => 'yesterday-ish', 'latest' => '2.1.0']],
    'notes not a string' => [['checked_at' => '2026-10-20T00:00:00Z', 'latest' => '2.1.0', 'notes_url' => ['x']]],
]);

it('orders versions by semantic-version precedence', function (string $newer, string $older): void {
    expect(UpdateStatus::compare($newer, $older))->toBeGreaterThan(0)
        ->and(UpdateStatus::compare($older, $newer))->toBeLessThan(0)
        ->and(UpdateStatus::compare($newer, $newer))->toBe(0);
})->with([
    ['2.0.1', '2.0.0'],
    ['1.10.0', '1.9.0'],
    ['2.0.0', '2.0.0-rc.1'],
    ['2.0.0-rc.2', '2.0.0-rc.1'],
    ['2.0.0-alpha.10', '2.0.0-alpha.9'],
    ['2.0.0-beta.1', '2.0.0-alpha.2'],
    ['2.0.0-alpha.beta', '2.0.0-alpha.1'],
    ['2.0.0-alpha.1', '2.0.0-alpha'],
    ['3.0.0-alpha.1', '2.9.9'],
]);

it('parses the instance and updates blocks of runtime.json, and treats malformed ones as absent', function (): void {
    $document = RuntimeFixture::document([
        'instance' => ['version' => '2.0.0', 'mode' => 'proxy', 'digests' => ['app' => 'ghcr.io/a/b@sha256:'.str_repeat('a', 64)], 'catcher_url' => 'http://localhost:8025'],
        'updates' => ['check' => true, 'manifest_url' => UPDATE_CHECK_URL],
    ]);
    $state = RuntimeDocument::parse($document);
    $bad = static fn (array $instance): mixed => RuntimeDocument::parse(RuntimeFixture::document(['instance' => $instance]))->instance;

    expect($state->instance?->mode)->toBe('proxy')->and($state->instance?->digests)->toHaveKey('app')
        ->and($state->instance?->catcherUrl)->toBe('http://localhost:8025')->and($state->updateManifestUrl)->toBe(UPDATE_CHECK_URL)
        ->and(RuntimeDocument::parse(RuntimeFixture::document())->instance)->toBeNull()
        ->and(RuntimeDocument::parse(RuntimeFixture::document())->updateManifestUrl)->toBeNull()
        ->and($bad(['version' => 'v2']))->toBeNull()
        ->and($bad(['digests' => ['app' => 'ghcr.io/a/b:latest']]))->toBeNull()
        ->and($bad(['digests' => ['APP' => 'ghcr.io/a/b@sha256:'.str_repeat('a', 64)]]))->toBeNull()
        ->and($bad(['digests' => 'ghcr.io/a/b']))->toBeNull()
        ->and($bad(['catcher_url' => 'javascript:alert(1)']))->toBeNull()
        ->and($bad(['mode' => 'cloud']))->toBeNull()
        ->and($bad([]))->not->toBeNull();
});
