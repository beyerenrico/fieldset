<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Admin\BreakGlassOwners;
use App\Domain\Identity\Sso\Admin\SetSsoEnforcement;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Enforcement of the SSO page: the switch, the break-glass Owner and the preconditions, each stated in
 * words as met or not met for the current selection (SetSsoEnforcement::unmet), so the admin sees what is
 * missing before saving.
 */
final class EnforcementSchema
{
    /** @return list<Section> */
    public static function components(Organization $org, IdentityProvider $provider, User $actor): array
    {
        return [
            Section::make(__('sso_admin.enforcement.title'))
                ->description(__('sso_admin.enforcement.help'))
                ->schema([
                    Toggle::make('enforce_sso')->label(__('sso_admin.enforcement.enforce'))
                        ->helperText(__('sso_admin.enforcement.enforce_help'))->live(),
                    Select::make('break_glass_user_id')->label(__('sso_admin.enforcement.break_glass'))
                        ->helperText(__('sso_admin.enforcement.break_glass_help'))
                        ->options(self::ownerOptions($org))->live(),
                    Text::make(__('sso_admin.enforcement.preconditions')),
                    UnorderedList::make(fn (Get $get): array => self::preconditions($provider, $actor, $get('break_glass_user_id'))),
                ]),
        ];
    }

    /** @return array{enforce_sso: bool, break_glass_user_id: int|null} */
    public static function fill(?IdentityProvider $provider): array
    {
        return ['enforce_sso' => (bool) $provider?->enforce_sso, 'break_glass_user_id' => $provider?->break_glass_user_id];
    }

    /** @return array<int, string> active Owners; those without MFA are marked */
    private static function ownerOptions(Organization $org): array
    {
        $options = [];
        foreach (app(BreakGlassOwners::class)->owners($org) as $owner) {
            $mfa = $owner->two_factor_confirmed_at !== null && $owner->two_factor_secret !== null;
            $options[$owner->id] = __($mfa ? 'sso_admin.enforcement.owner_option' : 'sso_admin.enforcement.owner_option_no_mfa', [
                'name' => $owner->name, 'email' => $owner->email,
            ]);
        }

        return $options;
    }

    /** @return list<Text> */
    private static function preconditions(IdentityProvider $provider, User $actor, mixed $breakGlassId): array
    {
        $breakGlass = is_numeric($breakGlassId) ? User::query()->find((int) $breakGlassId) : null;
        $conditions = app(SetSsoEnforcement::class)->conditions($provider, $breakGlass, $actor);
        $conditions['break_glass'] = $conditions['break_glass'] === null;

        $items = [];
        foreach ($conditions as $condition => $met) {
            $items[] = Text::make(__($met ? 'sso_admin.enforcement.met' : 'sso_admin.enforcement.unmet', [
                'condition' => __('sso_admin.enforcement.conditions.'.$condition),
            ]))
                ->icon($met ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedXCircle)
                ->color($met ? 'success' : 'danger');
        }

        return $items;
    }
}
