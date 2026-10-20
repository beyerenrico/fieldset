<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Platform\Actions\RevokePlatformAdmin;
use App\Domain\Platform\Health\SystemProbes;
use App\Domain\Platform\Instance\Updates\UpdateCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Number;
use Illuminate\Testing\TestResponse;
use Tests\Support\Instance\RuntimeFixture;

require_once __DIR__.'/Support.php';

/*
 * The `/platform` instance page (R39, R47, R48, R53, plan U20, ADR 0089): read-only, problems first (failed or stale
 * backup, disk, update available, backups held only on this host), each actionable status with its `fsa` command, then
 * version and digests, mode and URL, mail with the catcher link, backups, disk and updates. Operators only.
 */

const INSTANCE_PAGE_APP = 'ghcr.io/beyerenrico/form-schema-app@sha256:'.'1111111111111111111111111111111111111111111111111111111111111111';

const INSTANCE_PAGE_POSTGRES = 'docker.io/library/postgres@sha256:'.'2222222222222222222222222222222222222222222222222222222222222222';

const INSTANCE_PAGE_MANIFEST = 'https://github.com/beyerenrico/form-schema-app/releases/latest/download/release-manifest.json';

/** @param  array<string, mixed>  $row */
function instancePageRun(array $row): void
{
    DB::table('backup_runs')->insert([
        'kind' => 'scheduled', 'status' => 'succeeded', 'started_at' => now(), 'finished_at' => now(), 'backup' => 'scheduled-20261019T033000Z',
        'bytes' => 5 * 1024 * 1024, 'encrypted' => true, ...$row,
    ]);
}

/**
 * An fsa runtime file with the instance block (local mode, catcher on the loopback port) and, optionally, the update check.
 *
 * @param  array<string, mixed>  $overrides
 */
function instancePageRuntime(array $overrides = [], bool $updates = false): void
{
    RuntimeFixture::install(array_replace_recursive([
        'instance' => ['version' => '2.0.0', 'mode' => 'local', 'digests' => ['app' => INSTANCE_PAGE_APP, 'postgres' => INSTANCE_PAGE_POSTGRES], 'catcher_url' => 'http://localhost:8025'],
        'updates' => ['check' => $updates, 'manifest_url' => $updates ? INSTANCE_PAGE_MANIFEST : null],
    ], $overrides));
}

function instancePageDisk(float $freePercent): void
{
    test()->mock(SystemProbes::class, function ($mock) use ($freePercent): void {
        $mock->shouldReceive('diskSpace')->andReturn(['free' => $freePercent * 1024 ** 3, 'total' => 100.0 * 1024 ** 3]);
    });
}

/** @return list<string> the problem codes in page order */
function instancePageProblems(TestResponse $response): array
{
    preg_match_all('/data-instance-problem="([a-z_]+)"/', (string) $response->getContent(), $matches);

    return $matches[1];
}

function instancePageOpen(): TestResponse
{
    actingAsPlatformOperator(platformOperator());

    return test()->get('/platform/instance')->assertOk();
}

beforeEach(function (): void {
    config(['app.version' => '2.0.0', 'mail.capture' => true, 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mailpit']);
    instancePageDisk(50.0);
    Http::preventStrayRequests();
});

afterEach(fn () => RuntimeFixture::cleanup());

it('shows a failed last backup as the first problem, with the backup command, then disk, update and the on-host note', function (): void {
    instancePageRuntime(updates: true);
    instancePageDisk(8.0);
    Cache::forever(UpdateCheck::CACHE_KEY, ['checked_at' => now()->toIso8601ZuluString(), 'latest' => '2.1.0', 'notes_url' => 'https://example.com/notes/2.1.0', 'error' => null]);
    instancePageRun(['started_at' => now()->subDay()]);
    instancePageRun(['kind' => 'manual', 'status' => 'failed', 'reason' => 'pg_dump_failed', 'backup' => null, 'bytes' => null, 'started_at' => now()->subHour()]);

    $page = instancePageOpen();

    expect(instancePageProblems($page))->toBe(['backup_failed', 'disk_low', 'update_available', 'backups_on_host']);
    $page->assertSeeInOrder(['data-instance-problem="backup_failed"', 'data-instance-severity="danger"', 'pg_dump_failed', 'fsa backup', 'data-instance-problem="disk_low"', 'fsa status', 'data-instance-problem="update_available"', 'fsa upgrade'], false)
        ->assertSee(__('instance.problems.disk_low', ['percent' => 8]))
        ->assertSee('data-instance-backup-last="failed"', false);
    Http::assertNothingSent();
});

it('orders each backup alert by severity and names the command fsa status names', function (): void {
    instancePageRuntime();
    $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
    instancePageRun(['status' => 'succeeded', 'missing_files' => 3, 'started_at' => CarbonImmutable::parse('2026-10-19T03:30:00Z')]);
    instancePageRun(['status' => 'skipped', 'reason' => 'low_disk_space', 'backup' => null, 'bytes' => null, 'started_at' => CarbonImmutable::parse('2026-10-19T04:30:00Z')]);
    instancePageRun(['kind' => 'service', 'status' => 'failed', 'reason' => 'invalid_schedule', 'backup' => null, 'bytes' => null, 'started_at' => CarbonImmutable::parse('2026-10-19T11:00:00Z')]);

    $page = instancePageOpen();

    expect(instancePageProblems($page))->toBe(['backup_service_failed', 'backup_disk_space', 'backup_files_missing', 'backups_on_host']);
    $page->assertSeeInOrder(['invalid_schedule', 'fsa logs backup', __('instance.problems.backup_disk_space'), 'fsa backup', __('instance.problems.backup_files_missing', ['count' => 3])], false);
});

it('warns when the backup service of an fsa instance never reported', function (): void {
    instancePageRuntime();

    $page = instancePageOpen();

    expect(instancePageProblems($page))->toBe(['backup_silent']);
    $page->assertSeeInOrder([__('instance.problems.backup_silent'), 'fsa logs backup'], false)
        ->assertSee('data-instance-backup-last="none"', false);
});

it('shows version, digests, mode, URL and the loopback catcher link with its sign-in command', function (): void {
    instancePageRuntime();
    config(['app.revision' => 'abc1234']);

    instancePageOpen()
        ->assertSee('data-instance-version', false)->assertSee('2.0.0')->assertSee('abc1234')
        ->assertSee('data-instance-digest="app"', false)->assertSee(INSTANCE_PAGE_APP)->assertSee(INSTANCE_PAGE_POSTGRES)
        ->assertSee('data-instance-mode="local"', false)->assertSee(__('instance.access.modes.local'))->assertSee('http://localhost:8080')
        ->assertSee('data-instance-mail="capture"', false)->assertSee(__('instance.mail.capture'))
        ->assertSee('href="http://localhost:8025"', false)->assertSee('data-instance-catcher', false)
        ->assertSee('fsa config set mail smtp')->assertSee('fsa config show --catcher-login');
});

it('links the catcher behind the edge in domain mode without a sign-in command, and shows SMTP once configured', function (): void {
    instancePageRuntime([...RuntimeFixture::domain(), 'instance' => ['mode' => 'domain', 'catcher_url' => 'https://forms.example/_mail/']]);

    instancePageOpen()->assertSee('data-instance-mode="domain"', false)->assertSee('https://forms.example')
        ->assertSee('href="https://forms.example/_mail/"', false)->assertDontSee('fsa config show --catcher-login');

    config(['mail.capture' => false, 'mail.mailers.smtp.host' => 'smtp.example.com']);
    instancePageRuntime(['instance' => ['catcher_url' => null]]);
    $this->get('/platform/instance')->assertOk()->assertSee('data-instance-mail="smtp"', false)
        ->assertSee(__('instance.mail.smtp', ['host' => 'smtp.example.com']))->assertDontSee('data-instance-catcher', false)
        ->assertDontSee('fsa config set mail smtp');
});

it('shows the backup schedule, the next run and the last result from backup_runs', function (): void {
    instancePageRuntime();
    $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
    instancePageRun(['kind' => 'service', 'status' => 'started', 'schedule' => '03:30', 'time_zone' => 'Europe/Berlin', 'backup' => null, 'bytes' => null, 'started_at' => CarbonImmutable::parse('2026-10-18T09:00:00Z')]);
    instancePageRun(['started_at' => CarbonImmutable::parse('2026-10-19T01:30:00Z')]);

    $page = instancePageOpen();

    expect(instancePageProblems($page))->toBe(['backups_on_host']);
    $page->assertSee(__('instance.backup.daily', ['time' => '03:30', 'zone' => 'Europe/Berlin']))
        ->assertSee('data-instance-backup-last="succeeded"', false)->assertSee(__('instance.backup.statuses.succeeded'))
        ->assertSee(Number::fileSize(5 * 1024 * 1024, precision: 1))->assertSee(__('instance.backup.next_run'))
        ->assertSee('fsa config set backup-schedule HH:MM');
});

it('says there is no problem on an instance not run by fsa, and names no fsa command there', function (): void {
    $page = instancePageOpen();

    $page->assertSee('data-instance-problems="none"', false)->assertSee(__('instance.problems.none'))
        ->assertSee(__('instance.access.not_fsa'))->assertSee(__('instance.release.no_digests'))
        ->assertSee('data-instance-updates="off"', false)->assertSee(__('instance.updates.off'))
        ->assertDontSee('fsa config set update-check on')->assertDontSee('data-instance-command', false)
        ->assertSee('data-instance-disk-percent="50"', false);
    Http::assertNothingSent();
});

it('names the command that turns the update check on, and says when it has not run yet', function (): void {
    instancePageRuntime();
    instancePageOpen()->assertSee('data-instance-updates="off"', false)->assertSee('fsa config set update-check on');

    instancePageRuntime(updates: true);
    $this->get('/platform/instance')->assertOk()->assertSee('data-instance-updates="on"', false)->assertSee(__('instance.updates.never'));
    Http::assertNothingSent();
});

it('claims no up-to-date version before a check found the newest release', function (?string $error): void {
    instancePageRuntime(updates: true);
    if ($error !== null) {
        Cache::forever(UpdateCheck::CACHE_KEY, ['checked_at' => now()->toIso8601ZuluString(), 'latest' => null, 'notes_url' => null, 'error' => $error]);
    }

    instancePageOpen()->assertSee('data-instance-updates="on"', false)
        ->assertSee(__('instance.updates.unknown'))
        ->assertDontSee(__('instance.updates.current', ['version' => config('app.version')]));
})->with(['never checked' => null, 'first check failed' => 'unreachable']);

it('renders a notes URL that is not https as text, never as a link', function (string $notes): void {
    instancePageRuntime(updates: true);
    Cache::forever(UpdateCheck::CACHE_KEY, ['checked_at' => now()->toIso8601ZuluString(), 'latest' => '2.1.0', 'notes_url' => $notes, 'error' => null]);

    instancePageOpen()->assertSee('data-instance-updates="available"', false)
        ->assertSee('data-instance-notes-text', false)->assertDontSee('data-instance-notes>', false)
        ->assertDontSee('href="'.e($notes).'"', false);
})->with([
    'http' => 'http://example.com/notes',
    'javascript' => 'javascript:alert(1)',
    'https with a quote' => 'https://example.com/"onmouseover="x',
]);

it('shows the last check error and keeps the newest release seen before', function (): void {
    instancePageRuntime(updates: true);
    Cache::forever(UpdateCheck::CACHE_KEY, ['checked_at' => now()->toIso8601ZuluString(), 'latest' => '2.0.0', 'notes_url' => 'https://example.com/notes', 'error' => 'http_503']);

    instancePageOpen()->assertSee('data-instance-updates="on"', false)
        ->assertSee(__('instance.updates.errors.http', ['status' => '503']))->assertSee(__('instance.updates.current', ['version' => '2.0.0']))
        ->assertSee('href="https://example.com/notes"', false);
});

it('stays readable with a runtime file whose instance block is malformed or a file that is unusable', function (): void {
    instancePageRuntime(['instance' => ['mode' => 'galaxy']]);
    instancePageOpen()->assertSee('data-instance-mode="unknown"', false)->assertSee(__('instance.unknown'));

    file_put_contents(config('instance.runtime_path').'/runtime.json', '{not json');
    $this->get('/platform/instance')->assertOk()->assertSee(__('instance.title'));
});

it('speaks German to an operator who chose it', function (): void {
    instancePageRuntime();
    $operator = platformOperator();
    $operator->forceFill(['locale' => 'de'])->save();
    actingAsPlatformOperator($operator);

    $this->get('/platform/instance')->assertOk()->assertSee('Instanz')->assertSee('Auffangmodus')->assertSee('Probleme');
});

it('refuses guests, users without the operator role, revoked and deactivated operators', function (): void {
    $this->get('/platform/instance')->assertRedirect('/platform/login');

    $org = makeOrganization();
    $this->actingAs(ownerOf($org))->get('/platform/instance')->assertForbidden();
    $this->actingAs(memberOf($org, SystemRole::Admin))->get('/platform/instance')->assertForbidden();

    $revoked = platformOperator('Rita Revoked');
    actingAsPlatformOperator($revoked);
    app(RevokePlatformAdmin::class)->handle($revoked);
    $this->get('/platform/instance')->assertForbidden();

    $inactive = platformOperator('Ida Inactive');
    actingAsPlatformOperator($inactive);
    $inactive->forceFill(['status' => 'deactivated', 'deactivated_at' => now()])->save();
    $this->get('/platform/instance')->assertForbidden();
});
