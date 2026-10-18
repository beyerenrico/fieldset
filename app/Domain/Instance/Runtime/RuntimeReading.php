<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

/** One read of the runtime directory: the state in force, where it came from and, if any, why the file was unusable. */
final readonly class RuntimeReading
{
    public function __construct(
        public ?RuntimeState $state,
        public RuntimeSource $source,
        public ?string $problem = null,
    ) {}

    public static function notConfigured(): self
    {
        return new self(null, RuntimeSource::NotConfigured);
    }
}
