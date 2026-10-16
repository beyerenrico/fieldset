<?php

declare(strict_types=1);

namespace App\Domain\Mail\Delivery;

/**
 * Immutable, serializable snapshot of a DeliverableDocument (queued mailables carry this, never a
 * model). The file name is reduced to a safe single path segment.
 */
final readonly class DocumentFile implements DeliverableDocument
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $filename,
        public string $mime,
        public int $size,
        public ?string $submissionUuid = null,
        public ?int $renditionId = null,
    ) {}

    public static function from(DeliverableDocument $document): self
    {
        return new self(
            $document->deliveryDisk(),
            $document->deliveryPath(),
            self::safeFilename($document->deliveryFilename()),
            $document->deliveryMimeType(),
            max(0, $document->deliverySize()),
            $document->deliverySubmissionUuid(),
            $document->deliveryRenditionId(),
        );
    }

    public static function safeFilename(string $filename): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '-', $filename), ' .-');

        return $clean === '' ? 'document' : mb_substr($clean, 0, 200);
    }

    public function deliveryDisk(): string
    {
        return $this->disk;
    }

    public function deliveryPath(): string
    {
        return $this->path;
    }

    public function deliveryFilename(): string
    {
        return $this->filename;
    }

    public function deliveryMimeType(): string
    {
        return $this->mime;
    }

    public function deliverySize(): int
    {
        return $this->size;
    }

    public function deliverySubmissionUuid(): ?string
    {
        return $this->submissionUuid;
    }

    public function deliveryRenditionId(): ?int
    {
        return $this->renditionId;
    }
}
