<?php

declare(strict_types=1);

namespace App\Domain\Documents\Designs\Actions;

use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Documents\Models\Design;
use App\Domain\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates a design with default (or given) settings and publishes version 1 right away, so every
 * design always has a version a document can pin. The first design of an organization is its default.
 */
final readonly class CreateDesign
{
    public const string DEFAULT_KEY = 'default';

    public function __construct(private PublishDesign $publish) {}

    /** @param  array<string, mixed>  $settings */
    public function handle(string $name, ?User $actor, array $settings = [], ?string $key = null): Design
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException(__('documents.errors.name_required'));
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(fn (): Design => $this->create($name, $actor, $settings, $key));
            } catch (UniqueConstraintViolationException $e) {
                // A concurrent create took the key (or the default flag): pick the next free key.
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    /** @param  array<string, mixed>  $settings */
    private function create(string $name, ?User $actor, array $settings, ?string $key): Design
    {
        $design = Design::query()->create([
            'key' => $this->freeKey($key ?? self::keyFromName($name)),
            'name' => $name,
            'is_default' => ! Design::query()->where('is_default', true)->exists(),
            'settings' => DesignSettings::fromArray($settings)->toArray(),
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);
        $this->publish->handle($design, (string) __('documents.versions.initial_note'), $actor, null, 'design.created');

        return $design->refresh();
    }

    public static function keyFromName(string $name): string
    {
        $key = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(48, '')->value();

        return preg_match('/^[a-z]/', $key) === 1 ? $key : 'design_'.$key;
    }

    private function freeKey(string $base): string
    {
        $taken = Design::query()->where('key', 'like', $base.'%')->pluck('key')->all();
        $key = $base;
        $suffix = 1;
        while (in_array($key, $taken, true)) {
            $suffix++;
            $key = $base.'_'.$suffix;
        }

        return $key;
    }
}
