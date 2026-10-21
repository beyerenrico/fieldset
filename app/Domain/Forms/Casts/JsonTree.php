<?php

declare(strict_types=1);

namespace App\Domain\Forms\Casts;

use Fieldset\Json\Json;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Stores JSON documents (form definitions, submission data) faithfully: values are decoded as
 * stdClass/list trees so `{}` and `[]` stay distinct (checksums, JsonLogic truthiness).
 *
 * @implements CastsAttributes<mixed, mixed>
 */
final class JsonTree implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null ? null : Json::decode((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            throw new InvalidArgumentException("Assign a decoded JSON tree to [{$key}], not a string.");
        }

        return Json::encode($value);
    }
}
