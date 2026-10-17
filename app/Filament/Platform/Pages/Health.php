<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Health\HealthResult;
use App\Domain\Platform\Health\PlatformHealth;
use App\Domain\Platform\PlatformAdmins;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use BackedEnum;
use Carbon\CarbonInterface;
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

    /** @return list<HealthResult> details ready to print: points in time in the display timezone and locale */
    public function results(): array
    {
        return array_map($this->displayed(...), app(PlatformHealth::class)->run());
    }

    private function displayed(HealthResult $result): HealthResult
    {
        $details = array_map(
            static fn (string|int|CarbonInterface $value): string|int => $value instanceof CarbonInterface
                ? $value->setTimezone(SubmissionResource::displayTimezone())->settings(['locale' => app()->getLocale()])->isoFormat('L LTS')
                : $value,
            $result->details,
        );

        return new HealthResult($result->key, $result->status, $result->summary, $details);
    }
}
