<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

afterEach(function (): void {
    File::deleteDirectory(storage_path('framework/testing/spa'));
});

it('serves the field app shell for client routes with a strict CSP', function (): void {
    $index = public_path('app/index.html');
    $existed = is_file($index);
    if (! $existed) {
        File::ensureDirectoryExists(dirname($index));
        File::put($index, '<!doctype html><div id="root"></div>');
    }

    $this->get('/app/forms/12')->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertHeader('Content-Security-Policy')
        ->assertSee('id="root"', false);
    expect($this->get('/app')->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
    // The service worker precaches the shell under /app/shell (ADR 0035), so it keeps this CSP offline.
    expect($this->get('/app/shell?__WB_REVISION__=a')->assertOk()->headers->get('Content-Security-Policy'))->toContain("worker-src 'self'");

    if (! $existed) {
        File::delete($index);
    }
});

it('serves the precached shell without a session, so the service worker cannot overwrite one', function (): void {
    $index = public_path('app/index.html');
    $existed = is_file($index);
    if (! $existed) {
        File::ensureDirectoryExists(dirname($index));
        File::put($index, '<!doctype html><div id="root"></div>');
    }

    // The worker fetches the shell in the background while the login page runs discovery: a session saved by the
    // shell request would drop the SSO start token discovery wrote (database driver, no locking; CI run 39747027552).
    $shell = $this->get('/app/shell?__WB_REVISION__=a')->assertOk();
    expect($shell->baseRequest->hasSession())->toBeFalse()
        ->and($shell->headers->getCookies())->toBe([])
        ->and($shell->headers->get('Content-Security-Policy'))->toContain("worker-src 'self'");
    // Client routes keep the web group: impersonation and device checks need the session.
    expect($this->get('/app/login')->baseRequest->hasSession())->toBeTrue();

    if (! $existed) {
        File::delete($index);
    }
});

it('lets only the same origin frame the preview', function (): void {
    $preview = public_path('form-preview/preview.html');
    $existed = is_file($preview);
    if (! $existed) {
        File::ensureDirectoryExists(dirname($preview));
        File::put($preview, '<!doctype html>preview');
    }

    expect($this->get('/form-preview/')->assertOk()->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'");

    if (! $existed) {
        File::delete($preview);
    }
});
