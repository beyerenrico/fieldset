<?php

declare(strict_types=1);

namespace App\Domain\Documents\Renditions;

use App\Domain\Documents\Models\Rendition;

/** JSON shape of a rendition in API responses (§8.10: show the form and design version it used). */
final class RenditionPresenter
{
    /** @return array<string, mixed> */
    public static function present(Rendition $rendition): array
    {
        return [
            'uuid' => $rendition->uuid,
            'form_version_id' => $rendition->form_version_id,
            'design_version_id' => $rendition->design_version_id,
            'renderer_version' => $rendition->renderer_version,
            'page_count' => $rendition->page_count,
            'size' => $rendition->size,
            'file_sha256' => $rendition->file_sha256,
            'input_hash' => $rendition->input_hash,
            'created_at' => $rendition->created_at->toIso8601String(),
        ];
    }
}
