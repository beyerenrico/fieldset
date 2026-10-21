<?php

declare(strict_types=1);

use App\Domain\Instance\Release\Console\ReleaseCheckPgDumpCommand;
use App\Domain\Instance\Release\Console\ReleaseManifestCommand;
use App\Domain\Instance\Release\Console\ReleaseVersionCommand;
use App\Domain\Platform\Http\EnforceImpersonationWindow;
use App\Http\Api\ApiException;
use App\Http\Api\ApiExceptionRenderer;
use App\Http\Api\V1\Middleware\ResolveOrganization;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EndRevokedDeviceSession;
use App\Http\Middleware\LimitApiPayload;
use App\Http\Middleware\ResolveInstanceRuntime;
use App\Http\Middleware\Security\SecureCookies;
use App\Http\Middleware\Security\SecurityHeaders;
use App\Http\Middleware\Security\TrustedHosts;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    // Release tooling for CI and the local harness (ADR 0067). It lives in the instance domain, which code under
    // app/Console may not use (tests/Arch/InstanceBoundaryTest.php), so it is registered here.
    ->withCommands([ReleaseManifestCommand::class, ReleaseVersionCommand::class, ReleaseCheckPgDumpCommand::class])
    ->withMiddleware(function (Middleware $middleware): void {
        // ADR 0040: SecurityHeaders is outermost, so early answers (413, 503, CORS preflight) get headers too;
        // SecureCookies needs the proxy-aware scheme and runs after TrustProxies.
        // ADR 0074: ResolveInstanceRuntime (instance URL, gate, tunnel policy from FIELDSET_RUNTIME_PATH; a no-op without
        // it) runs after the framework's TrustHosts and TrustProxies and before SecureCookies and route middleware.
        $middleware->prepend([SecurityHeaders::class, AssignRequestId::class, LimitApiPayload::class]);
        $middleware->append([ResolveInstanceRuntime::class, SecureCookies::class]);
        // Behind a TLS-terminating reverse proxy: which proxies are trusted is read per request from
        // config('trustedproxy.proxies') (TRUSTED_PROXIES: IPs/CIDRs or *; empty = none). Config is not loaded yet here.
        // X-Forwarded-Port stays trusted: Traefik (proxy mode) and host proxies set it. On an `fsa` instance links take
        // their port from the configured URL, never from it, and the edge strips it (ADR 0079).
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
        // Host allow-list (APP_URL host, loopback, TRUSTED_HOSTS, runtime hosts) outside local/testing: no
        // reset-link poisoning.
        $middleware->trustHosts(at: TrustedHosts::patterns(...), subdomains: false);
        $middleware->statefulApi();
        $middleware->appendToGroup('web', [SetLocale::class, EndRevokedDeviceSession::class]);
        $middleware->appendToGroup('api', [SetLocale::class, EndRevokedDeviceSession::class]);
        $middleware->appendToGroup('web', [EnforceImpersonationWindow::class])->appendToGroup('api', [EnforceImpersonationWindow::class]); // ADR 0041
        $middleware->redirectGuestsTo('/admin/login');
        $middleware->throttleApi('api');
        $middleware->alias(['organization' => ResolveOrganization::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
        // Domain 4xx answers (validation, conflicts, not found) are client errors, not incidents.
        $exceptions->dontReportWhen(fn (Throwable $e): bool => $e instanceof ApiException && $e->status < 500);
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*')
            ? app(ApiExceptionRenderer::class)->render($e, $request)
            : null);
    })->create();
