<?php

declare(strict_types=1);

use App\Http\Api\ApiExceptionRenderer;
use App\Http\Api\V1\Middleware\ResolveOrganization;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EndRevokedDeviceSession;
use App\Http\Middleware\LimitApiPayload;
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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([AssignRequestId::class, LimitApiPayload::class]);
        $middleware->statefulApi();
        $middleware->appendToGroup('web', [SetLocale::class, EndRevokedDeviceSession::class]);
        $middleware->appendToGroup('api', [SetLocale::class, EndRevokedDeviceSession::class]);
        $middleware->redirectGuestsTo('/admin/login');
        $middleware->throttleApi('api');
        $middleware->alias(['organization' => ResolveOrganization::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*')
            ? app(ApiExceptionRenderer::class)->render($e, $request)
            : null);
    })->create();
