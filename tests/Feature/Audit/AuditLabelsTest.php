<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLabels;
use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Privacy\Erasure\AccountScrubber;
use App\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

/*
 * Every audit event the code writes has a label in English and German: the headline fallback of AuditLabels
 * ("Api Token Created") must never be what an admin reads. Subjects show their name when it is known.
 */

/**
 * Every event name written in app/: the first argument of record()/system()/cli()/forUser() (also ternaries), the
 * trailing `$event` argument of an action's handle() and its `string $event = '…'` default, plus the model
 * events of LogsActivity.
 *
 * @return list<string>
 */
function auditEventsWrittenByTheCode(): array
{
    $events = ['created', 'updated', 'deleted', 'restored'];
    $literal = "'([a-z_]+\\.[a-z_]+)'";
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        preg_match_all('/(?:->record|->system|->cli|forUser)\(\s*((?:[^;()]|\([^;()]*\))*?)[,)]/s', $source, $calls);
        foreach ($calls[1] as $argument) {
            preg_match_all("/{$literal}/", $argument, $found);
            array_push($events, ...$found[1]);
        }
        preg_match_all("/->handle\\([^;]*?,\\s*{$literal}\\s*\\)/s", $source, $found);
        array_push($events, ...$found[1]);
        preg_match_all("/string \\\$event = {$literal}/", $source, $found);
        array_push($events, ...$found[1]);
    }
    $events = array_values(array_unique($events));
    sort($events);

    return $events;
}

/** @return list<string> dotted keys of a nested translation array */
function auditFlatKeys(array $tree, string $prefix = ''): array
{
    $keys = [];
    foreach ($tree as $key => $value) {
        array_push($keys, ...(is_array($value) ? auditFlatKeys($value, $prefix.$key.'.') : [$prefix.$key]));
    }

    return $keys;
}

it('has an English and a German label for every event the code writes', function (): void {
    $events = auditEventsWrittenByTheCode();
    // The scan sees every way events are written (direct, ternary, multi-line, handle() argument, default).
    expect($events)->toContain('auth.login', 'auth.mfa_enabled', 'form.duplicated', 'form.published', 'platform.impersonation_started',
        'platform.impersonation_ended', 'auth.failed_suppressed', 'api_token.created', 'api_token.first_used', 'api_token.revoked',
        'login_link.issued', 'login_link.used');

    $missing = [];
    foreach ($events as $event) {
        foreach (['en', 'de'] as $locale) {
            $label = trans('audit.events.'.$event, [], $locale);
            if (! is_string($label) || $label === 'audit.events.'.$event) {
                $missing[] = "{$locale}: {$event}";
            }
        }
    }
    expect($missing)->toBe([])
        ->and(auditFlatKeys(trans('audit.events', [], 'de')))->toEqualCanonicalizing(auditFlatKeys(trans('audit.events', [], 'en')))
        ->and(auditFlatKeys(trans('audit.attributes', [], 'de')))->toEqualCanonicalizing(auditFlatKeys(trans('audit.attributes', [], 'en')));
});

it('labels API token events and their subject', function (): void {
    app()->setLocale('de');
    expect(AuditLabels::event('api_token.created'))->toBe('API-Token erstellt')
        ->and(AuditLabels::event('api_token.first_used'))->toBe('API-Token erstmals verwendet')
        ->and(AuditLabels::event('api_token.revoked'))->toBe('API-Token widerrufen')
        ->and(AuditLabels::subjectType(PersonalAccessToken::class))->toBe('API-Token');

    app()->setLocale('en');
    expect(AuditLabels::event('api_token.created'))->toBe('API token created')
        ->and(AuditLabels::event('api_token.first_used'))->toBe('API token first used')
        ->and(AuditLabels::event('api_token.revoked'))->toBe('API token revoked')
        ->and(AuditLabels::subjectType(PersonalAccessToken::class))->toBe('API token');
});

it('shows subject names and erased actors in the audit log, subjects loaded in one query per type', function (): void {
    $org = makeOrganization();
    $owner = ownerOf($org);
    actingInAdmin($owner, $org);
    clearAuditLog();
    $forms = collect(['Service report', 'Site inspection', 'Handover'])->map(fn (string $name) => Form::factory()->create(['name' => $name]));
    foreach ($forms as $form) {
        app(AuditLogger::class)->record('form.exported', $form, [], $owner);
    }
    app(AuditLogger::class)->record('auth.mfa_enabled', $owner, [], $owner);
    $erased = memberOf($org);
    $erased->forceFill(['name' => AccountScrubber::ERASED_NAME])->save();
    $byErased = app(AuditLogger::class)->record('form.updated', $forms[1], [], $erased);
    app()->setLocale('de');

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ListAuditLogs::class)
        ->assertSee('Formular: Service report (#'.$forms[0]->id.')')
        ->assertSee('Formular: Handover (#'.$forms[2]->id.')')
        ->assertSee('Benutzer: '.$owner->name.' (#'.$owner->id.')')
        ->assertSee('Gelöschte Person');
    $formQueries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'from "forms"'));
    DB::disableQueryLog();

    expect($formQueries)->toHaveCount(1)
        ->and(AuditLabels::actor($byErased->load('causer')))->toBe('Gelöschte Person')
        ->and(app(TenantContext::class)->id())->toBe($org->id);
});
