<?php

declare(strict_types=1);

namespace App\Domain\Forms\Actions;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Support\DefinitionReport;
use App\Domain\Identity\Models\User;
use FormSchema\Json\Json;
use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Imports a definition JSON file as a new form (spec §7.6 import/export). The file may be a bare
 * definition or an export envelope `{ "definition": {...}, "design": ... }`.
 */
final readonly class ImportDefinition
{
    public const int MAX_BYTES = 2_000_000;

    public function __construct(private CreateForm $create) {}

    /** @return array{0: Form, 1: DefinitionReport} */
    public function handle(User $actor, string $json, ?string $name = null): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException(__('forms.errors.import_too_large'));
        }

        try {
            $decoded = Json::decode($json);
        } catch (JsonException) {
            throw new InvalidArgumentException(__('forms.errors.import_not_json'));
        }

        $definition = $decoded instanceof stdClass && ($decoded->definition ?? null) instanceof stdClass
            ? $decoded->definition
            : $decoded;

        if (! $definition instanceof stdClass) {
            throw new InvalidArgumentException(__('forms.errors.import_not_definition'));
        }

        $title = is_string($definition->title ?? null) && $definition->title !== '' ? $definition->title : __('forms.import.untitled');

        return $this->create->handle($actor, $name ?? $title, $definition, null, 'form.imported');
    }
}
