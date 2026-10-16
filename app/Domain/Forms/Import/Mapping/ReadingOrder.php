<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Mapping;

/**
 * Sorts placed items in reading order: by page, then line by line from the top, left to right
 * within a line. Items whose tops are within LINE_TOLERANCE points share a line.
 */
final class ReadingOrder
{
    private const float LINE_TOLERANCE = 6.0;

    /**
     * @template T of array{page: int, rect: array{0: float, 1: float, 2: float, 3: float}}
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public static function sort(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => [$a['page'], -$a['rect'][3], $a['rect'][0]] <=> [$b['page'], -$b['rect'][3], $b['rect'][0]]);

        $sorted = [];
        $line = [];
        foreach ($items as $item) {
            $first = $line[0] ?? null;
            if ($first !== null && ($item['page'] !== $first['page'] || $first['rect'][3] - $item['rect'][3] > self::LINE_TOLERANCE)) {
                array_push($sorted, ...self::leftToRight($line));
                $line = [];
            }
            $line[] = $item;
        }

        return [...$sorted, ...self::leftToRight($line)];
    }

    /**
     * @template T of array{page: int, rect: array{0: float, 1: float, 2: float, 3: float}}
     *
     * @param  list<T>  $line
     * @return list<T>
     */
    private static function leftToRight(array $line): array
    {
        usort($line, static fn (array $a, array $b): int => [$a['rect'][0], -$a['rect'][3]] <=> [$b['rect'][0], -$b['rect'][3]]);

        return $line;
    }
}
