<?php

declare(strict_types=1);

namespace Fieldset\Evaluation;

use Fieldset\Definition\Element;
use Fieldset\Json\JsonPointer;

/**
 * One occurrence of an element in the data: root-level elements occur once, row elements once
 * per row of every enclosing repeater.
 */
final readonly class Instance
{
    /**
     * @param  list<array{string, int}>  $rowPath
     * @param  string  $pointer  scope pointer, e.g. `/items/0/qty` (also for layout elements)
     * @param  string|null  $parentPointer  pointer of the enclosing container or repeater instance
     */
    public function __construct(
        public Element $element,
        public array $rowPath,
        public string $pointer,
        public ?string $parentPointer,
        public int $pageIndex,
    ) {}

    /**
     * @param  list<array{string, int}>  $rowPath
     */
    public static function pointerFor(array $rowPath, string $key): string
    {
        $pointer = '';

        foreach ($rowPath as [$repeater, $index]) {
            $pointer = JsonPointer::append(JsonPointer::append($pointer, $repeater), $index);
        }

        return JsonPointer::append($pointer, $key);
    }
}
