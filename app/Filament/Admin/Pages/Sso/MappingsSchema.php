<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Admin\DeleteClaimMapping;
use App\Domain\Identity\Sso\Admin\ProviderSettings;
use App\Domain\Identity\Sso\Admin\SaveClaimMapping;
use App\Domain\Identity\Sso\Admin\SsoTargets;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Claim mappings of the SSO page: a repeater of claim + value → role or group ("role:<id>" / "group:<id>").
 * Saving syncs the rows through SaveClaimMapping / DeleteClaimMapping in one transaction; a row's errors
 * land on that row's fields.
 */
final class MappingsSchema
{
    /** @return list<Section> */
    public static function components(Organization $org): array
    {
        return [
            Section::make(__('sso_admin.mappings.title'))
                ->description(__('sso_admin.mappings.help'))
                ->schema([
                    Repeater::make('rows')->hiddenLabel()
                        ->schema([
                            Hidden::make('id'),
                            TextInput::make('claim')->label(__('sso_admin.mappings.claim'))->placeholder(ProviderSettings::DEFAULT_GROUPS_CLAIM)->maxLength(120),
                            TextInput::make('value')->label(__('sso_admin.mappings.value'))->required()->maxLength(255)
                                ->helperText(__('sso_admin.mappings.value_help')),
                            Select::make('target')->label(__('sso_admin.mappings.target'))->required()->options(self::targetOptions($org)),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->reorderable(false)
                        ->addActionLabel(__('sso_admin.mappings.add')),
                ]),
        ];
    }

    /** @return array{rows: list<array<string, mixed>>} */
    public static function fill(?IdentityProvider $provider): array
    {
        $rows = [];
        foreach ($provider?->mappings()->orderBy('claim')->orderBy('value')->get() ?? [] as $mapping) {
            $rows[] = [
                'id' => $mapping->id, 'claim' => $mapping->claim, 'value' => $mapping->value,
                'target' => $mapping->role_id !== null ? 'role:'.$mapping->role_id : 'group:'.$mapping->group_id,
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $rows  repeater state keyed by item key
     *
     * @throws ValidationException keyed `<statePath>.rows.<item key>.<field>`
     */
    public static function sync(IdentityProvider $provider, array $rows, User $actor, string $statePath): void
    {
        DB::transaction(function () use ($provider, $rows, $actor, $statePath): void {
            $existing = $provider->mappings()->get()->keyBy('id');
            $kept = array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows));
            foreach ($existing->except($kept) as $removed) {
                app(DeleteClaimMapping::class)->handle($removed, $actor);
            }

            foreach ($rows as $key => $row) {
                $mapping = $existing->get((int) ($row['id'] ?? 0));
                $mapping = $mapping instanceof IdentityProviderMapping ? $mapping : null;
                if (! $mapping instanceof IdentityProviderMapping || ! self::unchanged($mapping, $row)) {
                    self::saveRow($provider, $row, $actor, $mapping, "{$statePath}.rows.{$key}");
                }
            }
        });
    }

    /** @param  array<string, mixed>  $row */
    private static function unchanged(IdentityProviderMapping $mapping, array $row): bool
    {
        $target = $mapping->role_id !== null ? 'role:'.$mapping->role_id : 'group:'.$mapping->group_id;
        $claim = filled($row['claim'] ?? null) ? trim((string) $row['claim']) : ProviderSettings::DEFAULT_GROUPS_CLAIM;

        return $claim === $mapping->claim && trim((string) ($row['value'] ?? '')) === $mapping->value && ($row['target'] ?? null) === $target;
    }

    /** @param  array<string, mixed>  $row */
    private static function saveRow(IdentityProvider $provider, array $row, User $actor, ?IdentityProviderMapping $mapping, string $path): void
    {
        [$kind, $id] = array_pad(explode(':', (string) ($row['target'] ?? ''), 2), 2, null);
        $input = [
            'claim' => isset($row['claim']) ? (string) $row['claim'] : null, 'value' => isset($row['value']) ? (string) $row['value'] : null,
            'role_id' => $kind === 'role' ? $id : null, 'group_id' => $kind === 'group' ? $id : null,
        ];

        try {
            app(SaveClaimMapping::class)->handle($provider, $input, $actor, $mapping);
        } catch (ValidationException $e) {
            $messages = [];
            foreach ($e->errors() as $field => $errors) {
                $messages[$path.'.'.(in_array($field, ['claim', 'value'], true) ? $field : 'target')] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }
    }

    /** @return array<string, array<string, string>> option groups: roles and groups */
    private static function targetOptions(Organization $org): array
    {
        $targets = app(SsoTargets::class);
        $prefix = static fn (string $kind, array $options): array => collect($options)
            ->mapWithKeys(static fn (string $label, int $id): array => ["{$kind}:{$id}" => $label])->all();

        return array_filter([
            (string) __('sso_admin.mappings.roles') => $prefix('role', $targets->roleOptions($org)),
            (string) __('sso_admin.mappings.groups') => $prefix('group', $targets->groupOptions($org)),
        ]);
    }
}
