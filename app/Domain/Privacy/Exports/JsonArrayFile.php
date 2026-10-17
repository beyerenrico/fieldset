<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Exports;

use RuntimeException;

/** Writes a JSON array item by item, so large exports never hold all rows in memory. */
final class JsonArrayFile
{
    /** @var resource */
    private $handle;

    private int $count = 0;

    public function __construct(string $path)
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The export file could not be written.');
        }
        $this->handle = $handle;
        fwrite($this->handle, "[\n");
    }

    /** @param  array<string, mixed>  $item */
    public function add(array $item): void
    {
        fwrite($this->handle, ($this->count > 0 ? ",\n" : '').json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->count++;
    }

    public function close(): int
    {
        fwrite($this->handle, "\n]\n");
        fclose($this->handle);

        return $this->count;
    }
}
