<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Health\HealthResult;
use App\Domain\Platform\Health\PlatformHealth;
use App\Domain\Platform\PlatformAdmins;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Instance health for operators (§8.12): queues, Gotenberg, mail, scheduler, disk. Checks run on each load. */
final class Health extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'health';

    protected string $view = 'filament.platform.pages.health';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(PlatformAdmins::class)->check($user);
    }

    public static function getNavigationLabel(): string
    {
        return __('platform.health.title');
    }

    public function getTitle(): string
    {
        return __('platform.health.title');
    }

    /** @return list<HealthResult> */
    public function results(): array
    {
        return app(PlatformHealth::class)->run();
    }
}
