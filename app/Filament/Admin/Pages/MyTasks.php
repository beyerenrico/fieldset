<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Enums\TaskStatus;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Tasks\TaskAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "My tasks" (§8.3): open review tasks assigned to the user or one of their groups that they may complete,
 * with approve / request changes. Visible to reviewers (`submissions.review`) and personal assignees.
 */
class MyTasks extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'my-tasks';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $access = app(TaskAccess::class);

        return $user instanceof User && Filament::getTenant() instanceof Organization
            && ($access->mayReview($user) || $access->hasAssignedTasks($user));
    }

    public static function getNavigationLabel(): string
    {
        return __('workflows.tasks.navigation');
    }

    public static function getNavigationBadge(): ?string
    {
        $user = Filament::auth()->user();
        $count = $user instanceof User ? self::openTasks($user)->count() : 0;

        return $count > 0 ? (string) $count : null;
    }

    public function getTitle(): string
    {
        return __('workflows.tasks.title');
    }

    public function table(Table $table): Table
    {
        return MyTasksTable::configure($table);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /** @return Builder<Task> */
    public static function openTasks(User $user): Builder
    {
        return app(TaskAccess::class)->actionable(Task::query(), $user)->where('status', TaskStatus::Open->value);
    }
}
