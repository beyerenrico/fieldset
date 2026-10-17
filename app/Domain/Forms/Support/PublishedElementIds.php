<?php

declare(strict_types=1);

namespace App\Domain\Forms\Support;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Schema\DefinitionTools;

/** Ids of the elements that appear in any published version of a form: their keys are locked (§7.6). */
final class PublishedElementIds
{
    /** @return list<string> */
    public static function of(Form $form): array
    {
        $ids = [];
        foreach ($form->versions()->get() as $version) {
            foreach (DefinitionTools::elements($version->definition) as $element) {
                if (is_string($element->id ?? null)) {
                    $ids[] = $element->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
