<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Builder;

use FormSchema\Json\Json;
use stdClass;

/** Semantic role vocabulary (spec roles.json, D11) as select options. */
final class RoleOptions
{
    /** @return array<string, string> */
    public static function all(): array
    {
        $vocabulary = Json::decode((string) file_get_contents(base_path('spec/form-definition/1.0/roles.json')));
        $roles = $vocabulary instanceof stdClass && $vocabulary->roles instanceof stdClass ? get_object_vars($vocabulary->roles) : [];
        $options = [];
        foreach ($roles as $role => $description) {
            $options[(string) $role] = $role.' — '.(is_string($description) ? $description : '');
        }

        return $options;
    }
}
