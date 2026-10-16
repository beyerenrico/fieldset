<?php

declare(strict_types=1);

namespace App\Domain\Mail\Delivery;

/**
 * A stored file that can be mailed (§8.8): a PDF rendition, an export, … Implemented by
 * DocumentFile and by the Documents module's Rendition model. Method names carry a "delivery"
 * prefix so an Eloquent model can implement the contract without clashing with its attributes.
 */
interface DeliverableDocument
{
    /** Filesystem disk name (config/filesystems.php). */
    public function deliveryDisk(): string;

    /** Path on that disk. */
    public function deliveryPath(): string;

    /** File name shown to the recipient, e.g. "LS-2026-0042.pdf". */
    public function deliveryFilename(): string;

    public function deliveryMimeType(): string;

    /** Size in bytes; decides between attachment and download link. */
    public function deliverySize(): int;

    /** Related submission, recorded on download links and in the audit log. */
    public function deliverySubmissionUuid(): ?string;

    /** Related rendition id, if the document is a PDF rendition. */
    public function deliveryRenditionId(): ?int;
}
