<?php

declare(strict_types=1);

namespace App\Domain\Mail\Delivery;

/**
 * How the documents of one mail travel: as attachments (within the size budget) or as expiring
 * links. Serializable; BrandedMail attaches the files and renders download buttons from it.
 */
final readonly class DeliveryPlan
{
    /**
     * @param  list<DocumentFile>  $attachments
     * @param  list<DeliveredLink>  $links
     */
    public function __construct(public array $attachments = [], public array $links = []) {}

    public function isEmpty(): bool
    {
        return $this->attachments === [] && $this->links === [];
    }

    /**
     * Tokens describing the first document. In attachment mode {{document.link}} becomes a
     * localized "see attachment" phrase, so templates read well in both modes.
     *
     * @return array<string, string>
     */
    public function tokens(): array
    {
        $link = $this->links[0] ?? null;
        if ($link instanceof DeliveredLink) {
            return [
                'document.link' => $link->url,
                'document.name' => $link->filename,
                'document.expires' => $link->expiresAt->settings(['locale' => app()->getLocale()])->isoFormat('L'),
            ];
        }

        $file = $this->attachments[0] ?? null;

        return $file instanceof DocumentFile
            ? ['document.link' => __('mail.document.attached', ['name' => $file->filename]), 'document.name' => $file->filename, 'document.expires' => '']
            : [];
    }

    /** @return array<string, mixed> */
    public function auditSummary(): array
    {
        return [
            'attached' => array_map(static fn (DocumentFile $f): array => ['filename' => $f->filename, 'rendition_id' => $f->renditionId], $this->attachments),
            'linked' => array_map(static fn (DeliveredLink $l): array => ['filename' => $l->filename, 'link_id' => $l->linkId, 'expires_at' => $l->expiresAt->toIso8601String()], $this->links),
        ];
    }
}
