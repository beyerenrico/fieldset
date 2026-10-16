<?php

declare(strict_types=1);

namespace App\Domain\Forms\Actions;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Schema\DefinitionTools;
use App\Domain\Forms\Support\BlankDefinition;
use App\Domain\Identity\Models\User;

/** Duplicates a form's draft with fresh ids; template lineage (`source`) is kept (D12). */
final readonly class DuplicateForm
{
    public function __construct(private CreateForm $create) {}

    public function handle(Form $form, User $actor): Form
    {
        $definition = DefinitionTools::regenerateIds($form->draft_definition, BlankDefinition::id(...));
        $definition->key = $form->key.'_copy';

        [$copy] = $this->create->handle($actor, __('forms.actions.copy_name', ['name' => $form->name]), $definition, $form->category, 'form.duplicated');

        return $copy;
    }
}
