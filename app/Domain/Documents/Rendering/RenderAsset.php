<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use Illuminate\Support\Facades\Storage;

/**
 * An image the document inlines: a stored attachment (disk + path) or in-memory bytes (preview
 * placeholders). Its sha256 is part of the canonical render input.
 */
final readonly class RenderAsset
{
    public function __construct(
        public string $kind,
        public string $mime,
        public string $sha256,
        public ?string $disk = null,
        public ?string $path = null,
        public ?string $data = null,
        public ?string $name = null,
    ) {}

    public static function fromBytes(string $kind, string $mime, string $bytes): self
    {
        return new self($kind, $mime, hash('sha256', $bytes), data: $bytes);
    }

    public function bytes(): ?string
    {
        if ($this->data !== null) {
            return $this->data;
        }
        if ($this->disk === null || $this->path === null) {
            return null;
        }

        return Storage::disk($this->disk)->get($this->path);
    }
}
