<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/*
 * Helpers for the app-side runtime tests (plan U10, ADR 0074), required by each test file in this directory.
 * The probes live under /api/ because the tunnel path policy lets that prefix through.
 */

/** Probe routes: the links a page would render, the resolved host and scheme, and a signed route to verify. */
function registerRuntimeProbes(): void
{
    Route::middleware('web')->get('api/_runtime-probe', fn (Request $request): array => [
        'url' => url('/admin'),
        'route' => route('field-app'),
        'signed' => URL::signedRoute('runtime.signed'),
        'host' => $request->getHost(),
        'secure' => $request->isSecure(),
        'app_url' => config('app.url'),
    ]);
    Route::middleware(['web', 'signed'])->get('api/_runtime-signed', fn (): string => 'signed ok')->name('runtime.signed');
    Route::getRoutes()->refreshNameLookups();
}

/**
 * One request from a given TCP peer (REMOTE_ADDR), without the sticky server variables of withServerVariables().
 *
 * @param  array<string, string>  $headers
 * @return TestResponse<Response>
 */
function runtimeRequest(string $uri, string $peer = '127.0.0.1', array $headers = [], string $method = 'GET'): TestResponse
{
    $test = test();
    $server = (fn (array $headers): array => $this->transformHeadersToServerVars($headers))->call($test, $headers);

    return $test->call($method, $uri, [], [], [], [...$server, 'REMOTE_ADDR' => $peer]);
}

/** @param  TestResponse<Response>  $response */
function runtimeCookie(TestResponse $response, string $name): ?Cookie
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name) {
            return $cookie;
        }
    }

    return null;
}
