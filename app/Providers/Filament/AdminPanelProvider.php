<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domain\Identity\Auth\SharedAppAuthentication;
use App\Domain\Identity\Models\Organization;
use App\Filament\Admin\Pages\NotificationPreferences;
use App\Filament\Admin\Pages\Tenancy\EditOrganizationProfile;
use App\Filament\Admin\Pages\Tenancy\RegisterOrganization;
use App\Http\Middleware\EndRevokedDeviceSession;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenantFromFilament;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->emailVerification()
            ->emailChangeVerification()
            ->profile(isSimple: false)
            ->multiFactorAuthentication([
                SharedAppAuthentication::make()->recoverable()->brandName((string) config('app.name')),
            ])
            ->tenant(Organization::class, slugAttribute: 'slug')
            ->tenantProfile(EditOrganizationProfile::class)
            ->tenantMiddleware([SetTenantFromFilament::class, SetLocale::class], isPersistent: true)
            ->colors(['primary' => Color::Blue])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([AccountWidget::class])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s') // no Reverb: the bell polls (§8.9)
            ->userMenuItems([
                Action::make('notificationPreferences')
                    ->label(fn (): string => __('notifications.preferences.title'))
                    ->icon(Heroicon::OutlinedBell)
                    ->url(fn (): string => NotificationPreferences::getUrl())
                    ->visible(fn (): bool => Filament::getTenant() !== null),
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
            ->authMiddleware([Authenticate::class]);

        if (config('app.allow_registration')) {
            $panel->registration()->tenantRegistration(RegisterOrganization::class);
        }

        return $panel;
    }
}
