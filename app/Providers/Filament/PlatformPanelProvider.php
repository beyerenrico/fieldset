<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domain\Identity\Auth\SharedAppAuthentication;
use App\Domain\Platform\Health\SchedulerHeartbeat;
use App\Domain\Platform\Http\EnforceImpersonationWindow;
use App\Domain\Platform\Http\ExitImpersonationController;
use App\Domain\Platform\Listeners\EndImpersonationOnAuthChange;
use App\Filament\Platform\Http\AuthenticatePlatformOperator;
use App\Filament\Platform\ImpersonationBanner;
use App\Filament\Platform\Pages\Auth\Login;
use App\Http\Middleware\EndRevokedDeviceSession;
use App\Http\Middleware\SetLocale;
use App\Support\Security\InitialsAvatarProvider;
use Filament\Facades\Filament;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Login as LoginEvent;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The /platform operator panel (BUILD_PROMPT §8.12, ADR 0041): organizations, health and audited
 * impersonation. Own password login with required MFA (no tenant SSO), no tenancy, operators only.
 * Registers the impersonation banner, its time limit for panel pages, the auth-change listener and
 * the scheduler heartbeat.
 */
class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('platform')
            ->path('platform')
            ->login(Login::class)
            ->authGuard('web')
            ->multiFactorAuthentication([
                SharedAppAuthentication::make()->recoverable()->brandName((string) config('app.name')),
            ], isRequired: true)
            ->brandName(fn (): string => __('platform.brand'))
            ->colors(['primary' => Color::Amber])
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->discoverResources(in: app_path('Filament/Platform/Resources'), for: 'App\Filament\Platform\Resources')
            ->discoverPages(in: app_path('Filament/Platform/Pages'), for: 'App\Filament\Platform\Pages')
            ->pages([])
            // Outside the operator auth middleware: while impersonating, the signed-in user is the target.
            ->routes(fn () => Route::post('impersonation/exit', ExitImpersonationController::class)
                ->middleware('throttle:30,1')->name('impersonation.exit'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                EndRevokedDeviceSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetLocale::class,
            ])
            ->authMiddleware([AuthenticatePlatformOperator::class], isPersistent: true);
    }

    public function boot(): void
    {
        FilamentView::registerRenderHook(PanelsRenderHook::BODY_START, fn (): HtmlString => app(ImpersonationBanner::class)->render());

        // Panel pages do not run the web middleware group: enforce the time limit when Filament serves them.
        Filament::serving(function (): void {
            $response = app(EnforceImpersonationWindow::class)->check(request());
            if ($response !== null) {
                throw new HttpResponseException($response);
            }
        });

        Event::listen([LoginEvent::class, Logout::class], EndImpersonationOnAuthChange::class);
        Event::listen([ScheduledTaskStarting::class, ScheduledTaskSkipped::class], function (): void {
            app(SchedulerHeartbeat::class)->record();
        });
    }
}
