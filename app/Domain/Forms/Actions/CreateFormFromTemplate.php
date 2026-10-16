<?php

declare(strict_types=1);

namespace App\Domain\Forms\Actions;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Templates\TemplateCatalog;
use App\Domain\Identity\Models\User;

/** Creates a draft form from a bundled template, keeping the template lineage in `source` (D12). */
final readonly class CreateFormFromTemplate
{
    public function __construct(private CreateForm $create) {}

    public function handle(User $actor, string $templateKey, ?string $name = null, ?TemplateCatalog $catalog = null): Form
    {
        $definition = ($catalog ?? TemplateCatalog::bundled())->definition($templateKey);
        $title = is_string($definition->title ?? null) ? $definition->title : $templateKey;

        [$form] = $this->create->handle($actor, $name ?? $title, $definition, null, 'form.created_from_template');

        return $form;
    }
}
