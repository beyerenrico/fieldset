<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Preferences\ChannelPreferences;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The user's notification settings (§8.9): one row per event (fieldset with a legend) with a checkbox
 * per channel. User-level, so the same settings apply in every organization; reached from the user
 * menu, not the navigation. Shares ChannelPreferences with `PUT /api/v1/me/notification-preferences`.
 *
 * @property-read Schema $form
 */
class NotificationPreferences extends Page
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected static ?string $slug = 'notification-preferences';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isActive();
    }

    public function getTitle(): string
    {
        return __('notifications.preferences.title');
    }

    public function mount(): void
    {
        $this->form->fill(app(ChannelPreferences::class)->matrix($this->user()));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('notifications.preferences.title'))
                    ->description(__('notifications.preferences.description'))
                    ->schema(array_map($this->eventRow(...), NotificationEvent::cases()))
                    ->columns(['md' => 2]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('notifications.preferences.save'))->submit('save')->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $changes = [];
        foreach (NotificationEvent::cases() as $event) {
            foreach (ChannelPreferences::CHANNELS as $channel) {
                $changes[$event->value][$channel] = (bool) data_get($state, "{$event->value}.{$channel}", false);
            }
        }

        app(ChannelPreferences::class)->update($this->user(), $changes);
        Notification::make()->success()->title(__('notifications.preferences.saved'))->send();
    }

    private function eventRow(NotificationEvent $event): Fieldset
    {
        return Fieldset::make(__('notifications.events.'.$event->value))
            ->schema(array_map(
                static fn (string $channel): Checkbox => Checkbox::make("{$event->value}.{$channel}")
                    ->label(__('notifications.channels.'.$channel)),
                ChannelPreferences::CHANNELS,
            ))
            ->columns(2);
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
