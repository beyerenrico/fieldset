<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\SubmissionVisibility;
use App\Filament\Admin\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Admin\Resources\Submissions\Schemas\SubmissionInfolist;
use App\Filament\Admin\Resources\Submissions\Tables\SubmissionsTable;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin submissions inbox (M3-T09, BUILD_PROMPT §8.12). Read-only until the review actions of M4.
 * Rows follow the §8.6 "View all submissions" row through SubmissionVisibility — the same rules as the
 * API: Owner/Admin/Viewer all; Designer the forms they created plus their own; Reviewer the members of
 * their submission-sharing groups plus their own (assignment arrives with M4 tasks). ADR 0030.
 */
class SubmissionResource extends Resource
{
    protected static ?string $model = Submission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('inbox.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('inbox.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('inbox.navigation');
    }

    /** The number once allocated; drafts show a short uuid. */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record instanceof Submission ? ($record->number ?? __('inbox.label').' '.substr($record->uuid, 0, 8)) : null;
    }

    /** @return Builder<Submission> */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        // Same as parent::getEloquentQuery() for a tenant-scoped resource, typed to Submission.
        return app(SubmissionVisibility::class)->apply(Submission::query(), $user);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return SubmissionsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SubmissionInfolist::configure($schema);
    }

    public static function statusColor(SubmissionStatus $status): string
    {
        return match ($status) {
            SubmissionStatus::Draft => 'gray',
            SubmissionStatus::Submitted => 'info',
            SubmissionStatus::InReview, SubmissionStatus::ChangesRequested => 'warning',
            SubmissionStatus::Approved, SubmissionStatus::Completed => 'success',
            SubmissionStatus::Void => 'danger',
        };
    }

    /** Display time zone (§11): the user's, else the organization's. */
    public static function displayTimezone(): string
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();

        return match (true) {
            $user instanceof User && is_string($user->timezone) && $user->timezone !== '' => $user->timezone,
            $tenant instanceof Organization && $tenant->timezone !== '' => $tenant->timezone,
            default => (string) config('app.timezone'),
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubmissions::route('/'),
            'view' => ViewSubmission::route('/{record}'),
        ];
    }
}
