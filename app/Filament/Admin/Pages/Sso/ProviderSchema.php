<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Admin\ProviderSettings;
use App\Domain\Identity\Sso\Admin\SsoTargets;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\OidcFlavor;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Provider form of the SSO page: connection per type and flavor, the write-only client secret (never filled
 * back; the placeholder says whether one is stored) and JIT provisioning. Owner-only settings
 * (ProviderSettings::OWNER_ONLY, the type and the default role) are read-only below Owner level, with a note;
 * the page puts their stored values back before saving and SaveIdentityProvider enforces the rule.
 */
final class ProviderSchema
{
    /** @return list<Section> */
    public static function components(Organization $org, ?IdentityProvider $provider, User $actor, bool $owner): array
    {
        $targets = app(SsoTargets::class);
        $restricted = static fn (Field $field): Field => $field->disabled(! $owner);

        return [
            Section::make(__('sso_admin.provider.connection'))
                ->description(__('sso_admin.provider.connection_help'))
                ->schema([
                    ...self::ownerNote($provider, $owner),
                    ...self::common($restricted), ...self::oidc($provider, $restricted), ...self::saml($restricted),
                ])
                ->columns(2),
            Section::make(__('sso_admin.provider.provisioning'))
                ->description(__('sso_admin.provider.provisioning_help'))
                ->schema([
                    Toggle::make('jit_enabled')->label(__('sso_admin.provider.fields.jit_enabled'))
                        ->helperText(__('sso_admin.provider.help.jit_enabled'))->columnSpanFull(),
                    $restricted(Select::make('default_role_id')->label(__('sso_admin.provider.fields.default_role_id'))
                        ->options($targets->roleOptions($org, $owner ? $actor : null))->placeholder(__('sso_admin.provider.help.default_role_placeholder'))
                        ->helperText(__('sso_admin.provider.help.default_role_id'))),
                    Select::make('default_group_id')->label(__('sso_admin.provider.fields.default_group_id'))
                        ->options($targets->groupOptions($org))->placeholder(__('sso_admin.provider.help.default_group_placeholder')),
                    Toggle::make('force_reauth')->label(__('sso_admin.provider.fields.force_reauth'))
                        ->helperText(__('sso_admin.provider.help.force_reauth'))->columnSpanFull(),
                ])
                ->columns(2),
        ];
    }

    /**
     * Form state for the stored provider; the secret field always starts empty.
     *
     * @return array<string, mixed>
     */
    public static function fill(?IdentityProvider $provider): array
    {
        if (! $provider instanceof IdentityProvider) {
            return [
                'type' => IdentityProviderType::Oidc->value, 'flavor' => OidcFlavor::Generic->value,
                'scopes' => ProviderSettings::DEFAULT_SCOPES, 'groups_claim' => ProviderSettings::DEFAULT_GROUPS_CLAIM,
                'name_id_format' => ProviderSettings::NAME_ID_FORMATS['persistent'],
                'jit_enabled' => false, 'force_reauth' => false, 'trust_unverified_email' => false,
            ];
        }

        $state = ['type' => $provider->type->value, 'name' => $provider->name, 'client_secret' => null];
        foreach ([...ProviderSettings::OIDC_KEYS, ...ProviderSettings::SAML_KEYS] as $key) {
            $state[$key] = $provider->config[$key] ?? null;
        }
        foreach (ProviderSettings::COLUMNS as $column) {
            $state[$column] = $provider->getAttribute($column);
        }

        return $state;
    }

    /**
     * The stored values of the Owner-only settings, which replace whatever a non-Owner's form sent.
     *
     * @return array<string, mixed>
     */
    public static function ownerOnlyState(IdentityProvider $provider): array
    {
        $stored = self::fill($provider);
        $keys = [...ProviderSettings::OWNER_ONLY, ...ProviderSettings::OWNER_ONLY_COLUMNS];

        // Unset settings stay unset (the validator fills in their defaults, as for the stored provider).
        return array_filter(array_intersect_key($stored, array_flip($keys)), static fn (mixed $value): bool => $value !== null);
    }

    /** @return list<Text> */
    private static function ownerNote(?IdentityProvider $provider, bool $owner): array
    {
        if ($owner) {
            return [];
        }

        return [
            Text::make(__($provider instanceof IdentityProvider ? 'sso_admin.provider.owner_only_note' : 'sso_admin.provider.owner_only_setup'))
                ->icon(Heroicon::OutlinedLockClosed)->color('warning')->columnSpanFull(),
        ];
    }

    /** @return list<mixed> */
    private static function common(\Closure $restricted): array
    {
        return [
            $restricted(Select::make('type')->label(__('sso_admin.provider.fields.type'))->required()->live()->selectablePlaceholder(false)
                ->options([IdentityProviderType::Oidc->value => __('sso_admin.provider.types.oidc'), IdentityProviderType::Saml->value => __('sso_admin.provider.types.saml')])
                ->helperText(__('sso_admin.provider.help.type'))),
            TextInput::make('name')->label(__('sso_admin.provider.fields.name'))->required()->maxLength(120)
                ->helperText(__('sso_admin.provider.help.name')),
        ];
    }

    /** @return list<mixed> */
    private static function oidc(?IdentityProvider $provider, \Closure $restricted): array
    {
        $isOidc = static fn (Get $get): bool => $get('type') === IdentityProviderType::Oidc->value;
        $flavor = static fn (OidcFlavor $flavor): \Closure => static fn (Get $get): bool => $isOidc($get) && $get('flavor') === $flavor->value;
        $hasSecret = $provider?->type === IdentityProviderType::Oidc && $provider->hasClientSecret();

        return [
            $restricted(Select::make('flavor')->label(__('sso_admin.provider.fields.flavor'))->required()->live()->selectablePlaceholder(false)
                ->options(collect(OidcFlavor::cases())->mapWithKeys(fn (OidcFlavor $f): array => [$f->value => __('sso_admin.provider.flavors.'.$f->value)])->all())
                ->visible($isOidc)),
            $restricted(TextInput::make('tenant')->label(__('sso_admin.provider.fields.tenant'))->helperText(__('sso_admin.provider.help.tenant'))
                ->required()->maxLength(36)->visible($flavor(OidcFlavor::Entra))),
            $restricted(TextInput::make('issuer')->label(__('sso_admin.provider.fields.issuer'))->helperText(__('sso_admin.provider.help.issuer'))
                ->required()->maxLength(2048)->visible($flavor(OidcFlavor::Generic))),
            $restricted(TextInput::make('client_id')->label(__('sso_admin.provider.fields.client_id'))->required()->maxLength(255)
                ->autocomplete('off')->visible($isOidc)),
            TextInput::make('client_secret')->label(__('sso_admin.provider.fields.client_secret'))->password()->maxLength(4096)
                ->autocomplete('new-password')
                ->placeholder($hasSecret ? (string) __('sso_admin.provider.help.secret_stored') : null)
                ->helperText(__($hasSecret ? 'sso_admin.provider.help.secret_keep' : 'sso_admin.provider.help.secret_new'))
                ->dehydrated(fn (?string $state): bool => filled($state))->visible($isOidc),
            TagsInput::make('scopes')->label(__('sso_admin.provider.fields.scopes'))->helperText(__('sso_admin.provider.help.scopes'))
                ->visible($isOidc),
            $restricted(TextInput::make('groups_claim')->label(__('sso_admin.provider.fields.groups_claim'))->placeholder(ProviderSettings::DEFAULT_GROUPS_CLAIM)
                ->helperText(__('sso_admin.provider.help.groups_claim'))->maxLength(120)->visible($isOidc)),
            $restricted(Toggle::make('trust_unverified_email')->label(__('sso_admin.provider.fields.trust_unverified_email'))
                ->helperText(__('sso_admin.provider.help.trust_unverified_email'))->visible($flavor(OidcFlavor::Generic))->columnSpanFull()),
        ];
    }

    /** @return list<mixed> */
    private static function saml(\Closure $restricted): array
    {
        $isSaml = static fn (Get $get): bool => $get('type') === IdentityProviderType::Saml->value;
        $unstable = static fn (Get $get): bool => ! ProviderSettings::isStableNameIdFormat(is_string($get('name_id_format')) ? $get('name_id_format') : null);

        return [
            $restricted(TextInput::make('metadata_url')->label(__('sso_admin.provider.fields.metadata_url'))->helperText(__('sso_admin.provider.help.metadata_url'))
                ->maxLength(2048)->visible($isSaml)->columnSpanFull()),
            $restricted(Textarea::make('metadata_xml')->label(__('sso_admin.provider.fields.metadata_xml'))->helperText(__('sso_admin.provider.help.metadata_xml'))
                ->rows(6)->visible($isSaml)->columnSpanFull()),
            $restricted(Select::make('name_id_format')->label(__('sso_admin.provider.fields.name_id_format'))->live()
                ->options(self::nameIdFormatOptions())->placeholder(__('sso_admin.provider.name_id_formats.persistent'))
                ->helperText(fn (Get $get): string => __($unstable($get) ? 'sso_admin.provider.help.name_id_format_unstable' : 'sso_admin.provider.help.name_id_format'))
                ->visible($isSaml)),
            $restricted(TextInput::make('subject_attribute')->label(__('sso_admin.provider.fields.subject_attribute'))
                ->helperText(__('sso_admin.provider.help.subject_attribute'))->maxLength(255)
                ->required($unstable)->visible($isSaml)),
            $restricted(TextInput::make('email_attribute')->label(__('sso_admin.provider.fields.email_attribute'))->placeholder('email')
                ->helperText(__('sso_admin.provider.help.email_attribute'))->maxLength(255)->visible($isSaml)),
            $restricted(TextInput::make('name_attribute')->label(__('sso_admin.provider.fields.name_attribute'))->placeholder('name')
                ->maxLength(255)->visible($isSaml)),
            $restricted(TextInput::make('groups_attribute')->label(__('sso_admin.provider.fields.groups_attribute'))->placeholder('groups')
                ->helperText(__('sso_admin.provider.help.groups_attribute'))->maxLength(255)->visible($isSaml)),
        ];
    }

    /** @return array<string, string> format URN => label */
    public static function nameIdFormatOptions(): array
    {
        $options = [];
        foreach (ProviderSettings::NAME_ID_FORMATS as $name => $urn) {
            $options[$urn] = (string) __('sso_admin.provider.name_id_formats.'.$name);
        }

        return $options;
    }
}
