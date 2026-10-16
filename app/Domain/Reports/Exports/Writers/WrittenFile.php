<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports\Writers;

final readonly class WrittenFile
{
    public function __construct(public string $path, public string $extension) {}
}
