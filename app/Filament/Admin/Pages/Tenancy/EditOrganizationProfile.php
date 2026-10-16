<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Tenancy;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class EditOrganizationProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return __('identity.organization.settings');
    }

    public static function canView(Model $tenant): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && $tenant instanceof Organization
            && $user->hasPermissionIn($tenant, Permission::OrganizationManage);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('identity.organization.general'))->schema([
                TextInput::make('name')->label(__('identity.organization.name'))->required()->maxLength(120),
                Select::make('locale')->label(__('identity.organization.locale'))
                    ->options(['de' => 'Deutsch', 'en' => 'English'])->required(),
                Select::make('timezone')->label(__('identity.organization.timezone'))
                    ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                    ->searchable()->required(),
            ]),
            Section::make(__('identity.organization.company_details'))->schema([
                Textarea::make('company_details.address')->label(__('identity.organization.address'))->rows(3),
                TextInput::make('company_details.tax_id')->label(__('identity.organization.tax_id'))->maxLength(64),
                TextInput::make('company_details.iban')->label(__('identity.organization.iban'))->maxLength(34),
                TextInput::make('company_details.bic')->label(__('identity.organization.bic'))->maxLength(11),
                Textarea::make('company_details.legal_footer')->label(__('identity.organization.legal_footer'))->rows(2),
            ])->columns(2),
        ]);
    }
}
