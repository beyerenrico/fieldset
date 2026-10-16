<?php

declare(strict_types=1);

namespace FormSchema\Definition;

/**
 * Result of resolving a `var` path against the definition (spec §4.3).
 */
final readonly class Reference
{
    public const BUILTIN = 'builtin';

    public const SCOPE = 'scope';

    public const ELEMENT = 'element';

    public const UNKNOWN = 'unknown';

    /**
     * @param  Element|null  $element  the element of the first data key of the path
     * @param  Element|null  $target  the element the whole path addresses, following
     *                                `<index>.<key>` through repeater rows; null for other sub-paths
     */
    public function __construct(
        public string $kind,
        public ?Element $element = null,
        public ?Element $target = null,
    ) {}

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }

    public function isKnown(): bool
    {
        return $this->kind !== self::UNKNOWN;
    }
}
