<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MailMessages;

use App\Domain\Identity\Models\User;
use App\Domain\Mail\Actions\RetryMail;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Models\MailMessage;
use App\Filament\Admin\Resources\MailMessages\Pages\ListMailMessages;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** Read-only mail delivery log with filters and a Retry action for failed mail (§8.8). */
class MailMessageResource extends Resource
{
    protected static ?string $model = MailMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?int $navigationSort = 81;

    protected static ?string $slug = 'mail-log';

    public static function getNavigationGroup(): ?string
    {
        return __('mail.navigation.group');
    }

    public static function getModelLabel(): string
    {
        return __('mail.log.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mail.log.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('recipient_email')->label(__('mail.log.recipient'))
                ->formatStateUsing(fn (MailMessage $record): string => self::recipient($record)),
            TextEntry::make('status')->label(__('mail.log.status'))->badge()
                ->formatStateUsing(fn (MailStatus $state): string => $state->label())->color(fn (MailStatus $state): string => $state->color()),
            TextEntry::make('subject')->label(__('mail.log.subject'))->columnSpanFull(),
            TextEntry::make('mailable_class')->label(__('mail.log.type'))->formatStateUsing(fn (string $state): string => class_basename($state)),
            TextEntry::make('attempts')->label(__('mail.log.attempts')),
            TextEntry::make('submission_uuid')->label(__('mail.log.submission'))->placeholder('—'),
            TextEntry::make('message_id')->label(__('mail.log.message_id'))->placeholder('—'),
            TextEntry::make('queued_at')->label(__('mail.log.queued_at'))->dateTime(),
            TextEntry::make('sent_at')->label(__('mail.log.sent_at'))->dateTime()->placeholder('—'),
            TextEntry::make('failed_at')->label(__('mail.log.failed_at'))->dateTime()->placeholder('—'),
            TextEntry::make('error')->label(__('mail.log.error'))->placeholder('—')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('recipient_email')->label(__('mail.log.recipient'))->searchable(['recipient_email', 'recipient_name'])
                    ->formatStateUsing(fn (MailMessage $record): string => self::recipient($record)),
                TextColumn::make('subject')->label(__('mail.log.subject'))->limit(60)->searchable(),
                TextColumn::make('status')->label(__('mail.log.status'))->badge()
                    ->formatStateUsing(fn (MailStatus $state): string => $state->label())->color(fn (MailStatus $state): string => $state->color()),
                TextColumn::make('mailable_class')->label(__('mail.log.type'))->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('attempts')->label(__('mail.log.attempts'))->numeric(),
                TextColumn::make('created_at')->label(__('mail.log.queued_at'))->dateTime()->sortable(),
                TextColumn::make('sent_at')->label(__('mail.log.sent_at'))->dateTime()->sortable()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('mail.log.status'))->options(MailStatus::options()),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label(__('mail.log.created_from')),
                        DatePicker::make('until')->label(__('mail.log.created_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([ViewAction::make(), self::retryAction()]);
    }

    public static function retryAction(): Action
    {
        return Action::make('retry')
            ->label(__('mail.log.retry'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(__('mail.log.retry_confirm'))
            ->visible(fn (MailMessage $record): bool => Gate::allows('retry', $record))
            ->action(function (MailMessage $record): void {
                Gate::authorize('retry', $record);
                $actor = Filament::auth()->user();
                app(RetryMail::class)->handle($record, $actor instanceof User ? $actor : null);
                Notification::make()->success()->title(__('mail.log.retried'))->send();
            });
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListMailMessages::route('/')];
    }

    private static function recipient(MailMessage $record): string
    {
        return $record->recipient_name === null || $record->recipient_name === ''
            ? $record->recipient_email
            : "{$record->recipient_name} <{$record->recipient_email}>";
    }
}
