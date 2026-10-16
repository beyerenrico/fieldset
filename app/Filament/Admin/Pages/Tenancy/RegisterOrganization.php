<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Tenancy;

use App\Domain\Identity\Actions\CreateOrganization;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class RegisterOrganization extends RegisterTenant
{
    public static function getLabel(): string
    {
        return __('identity.organization.register');
    }

    public static function canView(): bool
    {
        return (bool) config('app.allow_registration');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('identity.organization.name'))->required()->maxLength(120),
            Select::make('locale')->label(__('identity.organization.locale'))
                ->options(['de' => 'Deutsch', 'en' => 'English'])->default('de')->required(),
            Select::make('timezone')->label(__('identity.organization.timezone'))
                ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                ->default('Europe/Berlin')->searchable()->required(),
        ]);
    }

    /** @param  array{name: string, locale: string, timezone: string}  $data */
    protected function handleRegistration(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return app(CreateOrganization::class)->handle($data, $user);
    }
}
