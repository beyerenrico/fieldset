<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Mail\Enums\SmtpEncryption;
use App\Domain\Mail\Models\MailSetting;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/** Form components of the Mail settings page. The stored password is never filled back. */
final class MailSettingsSchema
{
    /** @return array<int, Section> */
    public static function components(bool $hasPassword): array
    {
        return [
            Section::make(__('mail.settings.sender_section'))
                ->description(__('mail.settings.sender_section_help', ['address' => (string) config('mail.from.address')]))
                ->schema([
                    TextInput::make('sender_name')->label(__('mail.settings.sender_name'))
                        ->helperText(__('mail.settings.sender_name_help'))->maxLength(120),
                    TextInput::make('reply_to_address')->label(__('mail.settings.reply_to_address'))
                        ->helperText(__('mail.settings.reply_to_address_help'))->email()->maxLength(254),
                ])->columns(2),
            Section::make(__('mail.settings.smtp_section'))
                ->description(__('mail.settings.smtp_section_help'))
                ->schema([
                    Toggle::make('smtp_enabled')->label(__('mail.settings.smtp_enabled'))->live()->columnSpanFull(),
                    TextInput::make('smtp_host')->label(__('mail.settings.smtp_host'))->maxLength(255)
                        ->required(fn (Get $get): bool => (bool) $get('smtp_enabled'))->visible(fn (Get $get): bool => (bool) $get('smtp_enabled')),
                    TextInput::make('smtp_port')->label(__('mail.settings.smtp_port'))->integer()->minValue(1)->maxValue(65535)
                        ->required(fn (Get $get): bool => (bool) $get('smtp_enabled'))->visible(fn (Get $get): bool => (bool) $get('smtp_enabled')),
                    Select::make('smtp_encryption')->label(__('mail.settings.smtp_encryption'))->options(SmtpEncryption::options())
                        ->live()->afterStateUpdated(fn (?string $state, Set $set) => $set('smtp_port', SmtpEncryption::tryFrom((string) $state)?->defaultPort()))
                        ->required(fn (Get $get): bool => (bool) $get('smtp_enabled'))->visible(fn (Get $get): bool => (bool) $get('smtp_enabled')),
                    TextInput::make('smtp_from_address')->label(__('mail.settings.smtp_from_address'))->email()->maxLength(254)
                        ->helperText(__('mail.settings.smtp_from_address_help'))
                        ->required(fn (Get $get): bool => (bool) $get('smtp_enabled'))->visible(fn (Get $get): bool => (bool) $get('smtp_enabled')),
                    TextInput::make('smtp_username')->label(__('mail.settings.smtp_username'))->maxLength(255)
                        ->autocomplete('off')->visible(fn (Get $get): bool => (bool) $get('smtp_enabled')),
                    TextInput::make('smtp_password')->label(__('mail.settings.smtp_password'))->password()->maxLength(255)
                        ->autocomplete('new-password')
                        ->placeholder($hasPassword ? (string) __('mail.settings.smtp_password_stored') : null)
                        ->helperText(__('mail.settings.smtp_password_help'))
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->visible(fn (Get $get): bool => (bool) $get('smtp_enabled')),
                ])->columns(2),
        ];
    }

    /**
     * Form state for stored settings; the password field always starts empty.
     *
     * @return array<string, mixed>
     */
    public static function fill(?MailSetting $settings): array
    {
        if ($settings === null) {
            return ['smtp_enabled' => false, 'smtp_encryption' => SmtpEncryption::StartTls->value, 'smtp_port' => 587];
        }

        return [
            'sender_name' => $settings->sender_name,
            'reply_to_address' => $settings->reply_to_address,
            'smtp_enabled' => $settings->smtp_enabled,
            'smtp_host' => $settings->smtp_host,
            'smtp_port' => $settings->smtp_port,
            'smtp_encryption' => $settings->smtp_encryption?->value,
            'smtp_username' => $settings->smtp_username,
            'smtp_password' => null,
            'smtp_from_address' => $settings->smtp_from_address,
        ];
    }
}
