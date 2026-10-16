<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Mail\Actions\SaveMailSettings;
use App\Domain\Mail\Actions\SendTestMail;
use App\Domain\Mail\Models\MailSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * Per-organization mail settings (§8.8): sender name, reply-to, optional own SMTP server and a
 * "Send test email" action. Requires the organization-settings capability (ADR 0017).
 *
 * @property-read Schema $form
 */
class MailSettings extends Page
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'mail-settings';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();

        return $user instanceof User && $tenant instanceof Organization
            && $user->isActive() && $user->hasPermissionIn($tenant, Permission::OrganizationManage);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('mail.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('mail.settings.title');
    }

    public function getTitle(): string
    {
        return __('mail.settings.title');
    }

    public function mount(): void
    {
        $this->form->fill(MailSettingsSchema::fill(MailSetting::current()));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(MailSettingsSchema::components(MailSetting::current()?->hasPassword() ?? false))
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
                        Action::make('save')->label(__('mail.settings.save'))->submit('save')->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        try {
            $settings = app(SaveMailSettings::class)->handle($data, $this->user());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $messages, string $key): array => ["data.{$key}" => $messages])->all(),
            );
        }

        $this->form->fill(MailSettingsSchema::fill($settings));
        Notification::make()->success()->title(__('mail.settings.saved'))->send();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTestEmail')
                ->label(__('mail.settings.send_test'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->requiresConfirmation()
                ->modalDescription(fn (): string => __('mail.settings.send_test_confirm', ['email' => $this->user()?->email]))
                ->action(function (): void {
                    $user = $this->user();
                    abort_unless($user instanceof User, 403);
                    app(SendTestMail::class)->handle($user);
                    Notification::make()->success()->title(__('mail.settings.test_queued', ['email' => $user->email]))->send();
                }),
        ];
    }

    private function user(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }
}
