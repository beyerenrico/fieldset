<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Pages;

use App\Domain\Forms\Builder\ElementFields;
use App\Domain\Forms\Builder\KeySuggester;
use App\Domain\Forms\Builder\NodeMapper;
use App\Domain\Forms\Builder\PaletteCatalog;
use App\Filament\Admin\Resources\Forms\Builder\ElementBlocks;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use JsonException;

/**
 * The Builder v2 inspector (ADR 0037): one node's fields, reused from Builder v1 (form settings, page
 * fields, ElementBlocks::fields with LogicEditor and CalculationEditor). It also reads the
 * InspectorScope the client sends with every call (key types, repeater row keys, scope keys, autoKey).
 *
 * @phpstan-type InspectorScope array{keyTypes: array<string, string>, rowKeys: array<string, list<string>>, scopeKeys: list<string>, autoKey: bool}
 */
final class CanvasInspectorSchema
{
    public static function configure(Schema $schema, ?string $kind, ?string $type): Schema
    {
        return $schema->statePath('inspectorData')->components(self::components($kind, $type));
    }

    /**
     * Parses the InspectorScope JSON; anything malformed is dropped rather than trusted.
     *
     * @return InspectorScope
     */
    public static function scope(string $json): array
    {
        try {
            $raw = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $raw = [];
        }
        $raw = is_array($raw) ? $raw : [];

        $keyTypes = array_filter(is_array($raw['keyTypes'] ?? null) ? $raw['keyTypes'] : [], is_string(...));
        $rowKeys = [];
        foreach (is_array($raw['rowKeys'] ?? null) ? $raw['rowKeys'] : [] as $repeater => $keys) {
            $rowKeys[(string) $repeater] = self::strings($keys);
        }

        return [
            'keyTypes' => array_combine(array_map(strval(...), array_keys($keyTypes)), array_values($keyTypes)),
            'rowKeys' => $rowKeys,
            'scopeKeys' => self::strings($raw['scopeKeys'] ?? []),
            'autoKey' => ($raw['autoKey'] ?? false) === true,
        ];
    }

    /**
     * A key from the label that is unique among the scope keys (`_2`, `_3`, …), or '' for an empty label.
     *
     * @param  list<string>  $taken
     */
    public static function uniqueKey(string $label, array $taken): string
    {
        $base = KeySuggester::fromLabel($label);
        if ($base === '' || ! in_array($base, $taken, true)) {
            return $base;
        }
        for ($n = 2; ; $n++) {
            $candidate = rtrim(substr($base, 0, 64 - strlen("_{$n}")), '_')."_{$n}";
            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }

    /** @return list<Component> */
    private static function components(?string $kind, ?string $type): array
    {
        return match ($kind) {
            NodeMapper::SETTINGS => [Section::make(__('forms.types.form'))->icon('heroicon-o-cog-6-tooth')
                ->schema([Hidden::make('_source'), ...FormEditorSchema::settings()])],
            NodeMapper::PAGE => [Section::make(__('forms.types.page'))->icon('heroicon-o-document')->schema(FormEditorSchema::pageFields())],
            NodeMapper::ELEMENT => is_string($type) && in_array($type, ElementFields::ALL_TYPES, true)
                ? [Section::make(__('forms.types.'.$type))->icon(PaletteCatalog::icon($type))->schema(ElementBlocks::fields($type))]
                : [],
            default => [Text::make(__('forms.canvas.inspector.empty'))],
        };
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return array_values(array_filter(is_array($values) ? $values : [], is_string(...)));
    }
}
