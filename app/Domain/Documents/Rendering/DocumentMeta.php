<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

/**
 * Document metadata in the canonical render input: who, which number, which versions. Never the
 * render time, so re-rendering the same input yields the same input hash (§8.5).
 */
final readonly class DocumentMeta
{
    public function __construct(
        public string $organizationName,
        public ?string $number,
        public string $status,
        public ?string $submittedAt,
        public string $timezone,
        public string $formKey,
        public string $formTitle,
        public ?int $formVersion,
        public ?int $formVersionId,
        public ?int $designVersion,
        public ?int $designVersionId,
        public string $rendererVersion,
        public bool $preview = false,
        public int $organizationId = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'organization_name' => $this->organizationName,
            'number' => $this->number,
            'status' => $this->status,
            'submitted_at' => $this->submittedAt,
            'timezone' => $this->timezone,
            'form_key' => $this->formKey,
            'form_title' => $this->formTitle,
            'form_version' => $this->formVersion,
            'form_version_id' => $this->formVersionId,
            'design_version' => $this->designVersion,
            'design_version_id' => $this->designVersionId,
            'renderer_version' => $this->rendererVersion,
            'preview' => $this->preview,
            'organization_id' => $this->organizationId,
        ];
    }
}
