<?php

declare(strict_types=1);

namespace App\Domain\Forms\Schema;

/** One human-readable change between two definitions, matched by element id (§7.6). */
final readonly class DiffEntry
{
    public const string ADDED = 'added';

    public const string REMOVED = 'removed';

    public const string CHANGED = 'changed';

    public const string MOVED = 'moved';

    /**
     * @param  list<string>  $properties  changed property names (for "changed")
     */
    public function __construct(
        public string $change,
        public string $id,
        public string $key,
        public string $type,
        public string $label,
        public array $properties = [],
    ) {}
}
