<?php

declare(strict_types=1);

namespace App\Domain\Forms\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\User;
use FormSchema\Json\Json;
use stdClass;

/** Exports a definition (draft or a version) plus its design reference as a JSON document. */
final readonly class ExportDefinition
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Form $form, ?FormVersion $version, User $actor): string
    {
        $definition = $version !== null ? $version->definition : $form->draft_definition;
        $settings = $definition->settings ?? null;
        $document = $settings instanceof stdClass ? ($settings->document ?? null) : null;

        $envelope = new stdClass;
        $envelope->definition = $definition;
        $envelope->design = $document instanceof stdClass ? ($document->design ?? 'default') : 'default';
        $envelope->exportedFrom = (object) ['version' => $version?->version, 'checksum' => $version?->checksum];

        $this->audit->record('form.exported', $form, ['version' => $version?->version], $actor);

        return Json::encode($envelope);
    }
}
