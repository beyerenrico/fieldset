<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Instance\Overview\InstanceOverview;
use App\Domain\Platform\PlatformAdmins;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;
use Throwable;

/**
 * The read-only instance page for operators (R39, R47, R48, plan U20, ADR 0089): problems first (failed or stale
 * backups, disk, an available update, backups held only on this host), then version and digests, mode and URL, mail with
 * the catcher link, backups, disk and the update check. Each actionable status names its `fsa` command; nothing here
 * changes the instance. Read on each load (InstanceOverview).
 */
final class Instance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'instance';

    protected string $view = 'filament.platform.pages.instance';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(PlatformAdmins::class)->check($user);
    }

    public static function getNavigationLabel(): string
    {
        return __('instance.title');
    }

    public function getTitle(): string
    {
        return __('instance.title');
    }

    /** @return array<string, mixed> see InstanceOverview::build() */
    public function overview(): array
    {
        return app(InstanceOverview::class)->build();
    }

    /** A point in time in the display timezone and locale; a dash when unknown. */
    public function when(CarbonInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            $time = $value instanceof CarbonInterface ? $value : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return '—';
        }

        return $time->setTimezone(SubmissionResource::displayTimezone())->settings(['locale' => app()->getLocale()])->isoFormat('L LTS');
    }

    public function bytes(int|float|null $bytes): string
    {
        return $bytes === null ? '—' : Number::withLocale(app()->getLocale(), static fn (): string => (string) Number::fileSize((int) $bytes, precision: 1));
    }
}
