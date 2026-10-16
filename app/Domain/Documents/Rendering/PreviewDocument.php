<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use App\Domain\Documents\Designs\DesignResolver;
use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Forms\Templates\TemplateCatalog;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use stdClass;

/**
 * "Preview PDF" (§8.2, §8.5): renders an unsaved definition with sample data and a design — the
 * design the definition selects (builder) or unsaved design settings on a sample form (design
 * editor). Nothing is stored; the PDF carries the DRAFT watermark unless the design disables it.
 */
final readonly class PreviewDocument
{
    public const string SAMPLE_TEMPLATE = 'service_report';

    public function __construct(
        private DesignResolver $designs,
        private RenderInputFactory $inputs,
        private DocumentRenderer $renderer,
    ) {}

    /** Builder preview: the definition with the design version it selects. */
    public function forDefinition(stdClass $definition, Organization $organization, User $user): RenderedDocument
    {
        $version = $this->designs->forDefinition($definition);

        return $this->render($definition, $version->designSettings(), $version, $organization, $user);
    }

    /** Design editor preview: draft settings applied to a bundled sample form. */
    public function forSettings(DesignSettings $settings, Organization $organization, User $user): RenderedDocument
    {
        return $this->render(TemplateCatalog::bundled()->definition(self::SAMPLE_TEMPLATE), $settings, null, $organization, $user);
    }

    private function render(stdClass $definition, DesignSettings $settings, ?DesignVersion $version, Organization $organization, User $user): RenderedDocument
    {
        return $this->renderer->render($this->inputs->forPreview($definition, $settings, $version, $organization, $user));
    }
}
