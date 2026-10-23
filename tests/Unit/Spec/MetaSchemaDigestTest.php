<?php

declare(strict_types=1);

/*
 * Docs site plan U8: the spec README states the meta-schema's SHA-256, so a validator that bundles meta-schema.json
 * instead of fetching its `$id` can check its copy. The digest must follow every change of the schema.
 */

/** @return array{readme: string, schema: string} the spec README and the meta-schema's bytes */
function metaSchemaSpecFiles(): array
{
    $dir = base_path('spec/form-definition/1.0');

    return ['readme' => (string) file_get_contents($dir.'/README.md'), 'schema' => (string) file_get_contents($dir.'/meta-schema.json')];
}

/** Whether the README states exactly one `Meta-schema SHA-256:` line and it is the schema's digest. */
function statedDigestMatches(string $readme, string $schema): bool
{
    preg_match_all('/^Meta-schema SHA-256: `([0-9a-f]{64})`$/m', $readme, $matches);

    return $matches[1] === [hash('sha256', $schema)];
}

it('states the SHA-256 of meta-schema.json in the spec README', function (): void {
    ['readme' => $readme, 'schema' => $schema] = metaSchemaSpecFiles();

    expect(statedDigestMatches($readme, $schema))->toBeTrue()
        ->and($readme)->toContain(hash_file('sha256', base_path('spec/form-definition/1.0/meta-schema.json')));
});

it('fails for a changed schema, a second digest line or none', function (): void {
    ['readme' => $readme, 'schema' => $schema] = metaSchemaSpecFiles();
    $digest = 'Meta-schema SHA-256: `'.hash('sha256', $schema).'`';

    expect(statedDigestMatches($readme, substr_replace($schema, ' ', -1, 0)))->toBeFalse()
        ->and(statedDigestMatches("{$digest}\n{$digest}\n", $schema))->toBeFalse()
        ->and(statedDigestMatches(str_replace($digest, '', $readme), $schema))->toBeFalse()
        ->and(statedDigestMatches("{$digest}\n", $schema))->toBeTrue();
});
