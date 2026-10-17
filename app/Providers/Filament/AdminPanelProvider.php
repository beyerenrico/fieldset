<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domain\Identity\Auth\SharedAppAuthentication;
use App\Domain\Identity\Models\Organization;
use App\Filament\Admin\Livewire\DatabaseNotifications;
use App\Filament\Admin\Pages\Auth\EditProfile;
use App\Filament\Admin\Pages\Auth\EmailChangeVerificationController;
use App\Filament\Admin\Pages\Auth\Login;
use App\Filament\Admin\Pages\Auth\RequestPasswordReset;
use App\Filament\Admin\Pages\Auth\ResetPassword;
use App\Filament\Admin\Pages\NotificationPreferences;
use App\Filament\Admin\Pages\Privacy\MyData;
use App\Filament\Admin\Pages\Tenancy\EditOrganizationProfile;
use App\Filament\Admin\Pages\Tenancy\RegisterOrganization;
use App\Http\Middleware\EndRevokedDeviceSession;
use App\Http\Middleware\Security\AdminSessionTimeout;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenantFromFilament;
use App\Http\Middleware\UseDefaultTenantOutsideTenantRoutes;
use App\Support\Security\InitialsAvatarProvider;
use Filament\Actions\Action;
use Filament\Auth\Http\Controllers\EmailChangeVerificationController as FilamentEmailChangeVerificationController;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Auth\Events\Login as LoginEvent;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function register(): void
    {
        parent::register();

        // The email-change link is refused for addresses managed by SSO (gate review M7).
        $this->app->bind(FilamentEmailChangeVerificationController::class, EmailChangeVerificationController::class);
    }

    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)                                            // email-first with home-realm discovery (ADR 0036)
            // Local initials avatars: ui-avatars.com would leak names and is blocked by the CSP (ADR 0040).
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->passwordReset(RequestPasswordReset::class, ResetPassword::class) // refused for domains that enforce SSO
            ->emailVerification()
            ->emailChangeVerification()
            ->profile(EditProfile::class, isSimple: false)                  // SSO-managed addresses stay put (gate review M7)
            ->multiFactorAuthentication([
                SharedAppAuthentication::make()->recoverable()->brandName((string) config('app.name')),
            ])
            ->tenant(Organization::class, slugAttribute: 'slug')
            ->tenantProfile(EditOrganizationProfile::class)
            ->tenantMiddleware([SetTenantFromFilament::class, SetLocale::class], isPersistent: true)
            ->colors(['primary' => Color::Blue])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([]) // App\Filament\Admin\Pages\Dashboard (role-aware, discovered) replaces Filament's dashboard
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([AccountWidget::class])
            ->databaseNotifications(livewireComponent: DatabaseNotifications::class)   // inbox membership rule (M4 review S2)
            ->databaseNotificationsPolling('30s') // no Reverb: the bell polls (§8.9)
            ->userMenuItems([
                Action::make('notificationPreferences')
                    ->label(fn (): string => __('notifications.preferences.title'))
                    ->icon(Heroicon::OutlinedBell)
                    ->url(fn (): string => NotificationPreferences::getUrl())
                    ->visible(fn (): bool => Filament::getTenant() !== null),
                // GDPR self-service (export, erasure) one click away for every signed-in member (§11 Privacy).
                Action::make('myData')
                    ->label(fn (): string => __('privacy.my_data.title'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->url(fn (): string => MyData::getUrl())
                    ->visible(fn (): bool => MyData::canAccess()),
            ])
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
            ])
            ->authMiddleware([Authenticate::class])
            // Persistent: Livewire updates of admin pages count as activity (ASVS 3.3.2 idle/absolute timeouts).
            ->authMiddleware([AdminSessionTimeout::class], isPersistent: true)
            // Pages without an organization in the URL (profile) get the user's default one for their layout.
            ->authMiddleware([UseDefaultTenantOutsideTenantRoutes::class], isPersistent: true);

        if (config('app.allow_registration')) {
            $panel->registration()->tenantRegistration(RegisterOrganization::class);
        }

        return $panel;
    }

    public function boot(): void
    {
        Event::listen(LoginEvent::class, [AdminSessionTimeout::class, 'restart']);
    }
}
