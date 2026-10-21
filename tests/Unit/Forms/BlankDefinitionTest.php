<?php

declare(strict_types=1);

use App\Domain\Forms\Support\BlankDefinition;
use Fieldset\Json\Json;
use Fieldset\Support\SpecPaths;
use Fieldset\Validation\MetaSchemaValidator;

/* The blank definition names the spec by its $id (Fieldset Spec 1.0), not by an app URL. */

it('carries $schema equal to the meta-schema $id and validates', function (): void {
    $definition = BlankDefinition::make('pruefprotokoll', 'Prüfprotokoll', 'de-DE');
    $metaSchema = SpecPaths::readJson(SpecPaths::metaSchema());

    expect($definition->{'$schema'})->toBe('https://beyerenrico.github.io/fieldset/spec/form-definition/1.0/meta-schema.json')
        ->and($definition->{'$schema'})->toBe($metaSchema->{'$id'})
        ->and(SpecPaths::META_SCHEMA_ID)->toBe($metaSchema->{'$id'})
        ->and((new MetaSchemaValidator)->validate(Json::decode(Json::encode($definition)))->valid)->toBeTrue();
});

it('still accepts the pre-rename $schema URI, since $schema is pattern-only', function (): void {
    $definition = BlankDefinition::make('legacy', 'Legacy', 'de-DE');
    $definition->{'$schema'} = 'https://localhost/spec/form-definition/1.0/meta-schema.json';

    expect((new MetaSchemaValidator)->validate(Json::decode(Json::encode($definition)))->valid)->toBeTrue();
});
