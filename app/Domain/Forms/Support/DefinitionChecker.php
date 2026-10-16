<?php

declare(strict_types=1);

namespace App\Domain\Forms\Support;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use FormSchema\Lint\Linter;
use FormSchema\Validation\MetaSchemaValidator;

/** Runs the meta-schema check and the linter, with the form's published history (spec §7.5, §6). */
final readonly class DefinitionChecker
{
    public function __construct(
        private MetaSchemaValidator $metaSchema,
        private Linter $linter,
    ) {}

    public function check(mixed $definition, ?Form $form = null): DefinitionReport
    {
        $meta = $this->metaSchema->validate($definition);

        if (! $meta->valid) {
            return new DefinitionReport($meta->errors, []);
        }

        $published = $form === null ? [] : $form->versions()->get()
            ->map(fn (FormVersion $v): mixed => $v->definition)->values()->all();

        return new DefinitionReport([], $this->linter->lint($definition, array_values($published)));
    }
}
