<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Performance;

use App\Domain\Forms\Enums\FormStatus;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\Organization;

/** Resolves the organization and form arguments of the load and benchmark commands. */
final class TargetResolver
{
    /** By slug or id. */
    public static function organization(mixed $key): ?Organization
    {
        if (! is_string($key) || $key === '') {
            return null;
        }

        return Organization::query()->where('slug', $key)
            ->when(ctype_digit($key), fn ($q) => $q->orWhere('id', (int) $key))
            ->first();
    }

    /** A published form of the current tenant by key or id; without a key the first active one. */
    public static function form(mixed $key): ?Form
    {
        $query = Form::query()->whereNotNull('current_version_id');
        if (! is_string($key) || $key === '') {
            return $query->where('status', FormStatus::Active->value)->orderBy('id')->first();
        }

        return $query->where(fn ($q) => $q->where('key', $key)->when(ctype_digit($key), fn ($q) => $q->orWhere('id', (int) $key)))->first();
    }
}
