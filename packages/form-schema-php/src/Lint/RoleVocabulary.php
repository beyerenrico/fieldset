<?php

declare(strict_types=1);

namespace FormSchema\Lint;

use FormSchema\Support\SpecPaths;
use stdClass;

/**
 * The semantic role vocabulary of roles.json (spec §8).
 */
final readonly class RoleVocabulary
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(public array $roles) {}

    public static function load(?string $path = null): self
    {
        $json = SpecPaths::readJson($path ?? SpecPaths::roles());
        $roles = $json instanceof stdClass ? ($json->roles ?? null) : null;
        $roles = $roles instanceof stdClass ? $roles : new stdClass;

        return new self(array_map('strval', array_keys(get_object_vars($roles))));
    }

    public function contains(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
