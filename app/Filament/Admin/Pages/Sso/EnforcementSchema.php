<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\SsoDomain;
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
 * missing before saving. Below Owner level the switch is read-only while enforced (only an Owner turns it
 * off). Switching it on asks for confirmation (confirmation()), naming the domains, provider and Owner.
 */
final class EnforcementSchema
{
    /** @return list<Section> */
    public static function components(Organization $org, IdentityProvider $provider, User $actor, bool $owner): array
    {
        $locked = $provider->enforce_sso && ! $owner;

        return [
            Section::make(__('sso_admin.enforcement.title'))
                ->description(__('sso_admin.enforcement.help'))
                ->schema([
                    Toggle::make('enforce_sso')->label(__('sso_admin.enforcement.enforce'))
                        ->helperText(__($locked ? 'sso_admin.enforcement.owner_only_note' : 'sso_admin.enforcement.enforce_help'))
                        ->disabled($locked)->live(),
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

    /**
     * The confirmation text when the form switches enforcement on and every precondition holds; null when no
     * confirmation is needed (it stays off, it is on already, or saving will report what is missing).
     */
    public static function confirmation(IdentityProvider $provider, User $actor, mixed $enforce, ?User $breakGlass): ?string
    {
        if ($provider->enforce_sso || $enforce !== true || app(SetSsoEnforcement::class)->unmet($provider, $breakGlass, $actor) !== []) {
            return null;
        }
        $domains = SsoDomain::query()->withoutTenant()->where('organization_id', $provider->organization_id)
            ->whereNotNull('verified_at')->orderBy('domain')->pluck('domain')->all();

        return (string) __('sso_admin.enforcement.confirm_body', [
            'domains' => implode(', ', $domains), 'provider' => $provider->name, 'owner' => $breakGlass?->name,
        ]);
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
