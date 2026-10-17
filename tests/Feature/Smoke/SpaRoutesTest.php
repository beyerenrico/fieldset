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
