<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Tenancy;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Organization settings: name, language and time zone. Company details and the rest of the branding
 * have one place of truth, the Branding page (ManageBranding / UpdateBranding); this page never writes
 * them, so saving it cannot wipe keys set there.
 */
class EditOrganizationProfile extends EditTenantProfile
{
    /** The only organization columns this page may change. */
    public const array FIELDS = ['name', 'locale', 'timezone'];

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
            Text::make(__('identity.organization.company_details_hint')),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update(Arr::only($data, self::FIELDS));

        return $record;
    }
}
