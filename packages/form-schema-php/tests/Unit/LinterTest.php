<?php

declare(strict_types=1);

use FormSchema\Definition\Definition;
use FormSchema\Definition\InvalidDefinitionException;
use FormSchema\Lint\Linter;
use FormSchema\Lint\LintFinding;
use FormSchema\Lint\RoleVocabulary;

require_once __DIR__.'/../Support/helpers.php';

$lint = static fn (mixed $definition, array $published = []): array => fs_finding_set((new Linter)->lint($definition, $published));

$withTempFile = static function (string $contents, callable $callback): mixed {
    $file = tempnam(sys_get_temp_dir(), 'fs-roles');
    file_put_contents($file, $contents);

    try {
        return $callback($file);
    } finally {
        unlink($file);
    }
};

/*
 * Root: amount (number), customer (text), items (repeater: qty number, amount text, parts repeater: serial).
 * The probe element carrying the logic is appended at root (/pages/0/elements/3) or as the
 * fourth row element of items (/pages/0/elements/2/elements/3).
 */
$probeDefinition = static function (mixed $logic, bool $inRow, string $property = 'visibleIf'): stdClass {
    $probe = fs_element('text', 'probe', ['label' => 'Probe', $property => $logic]);
    $rows = [
        fs_element('number', 'qty', ['label' => 'Qty']),
        fs_element('text', 'amount', ['label' => 'Shadow']),
        fs_element('repeater', 'parts', ['label' => 'Parts', 'elements' => [fs_element('text', 'serial', ['label' => 'S'])]]),
    ];

    return fs_definition([
        fs_element('number', 'amount', ['label' => 'Amount']),
        fs_element('text', 'customer', ['label' => 'Customer']),
        fs_element('repeater', 'items', ['label' => 'Items', 'elements' => $inRow ? [...$rows, $probe] : $rows]),
        ...($inRow ? [] : [$probe]),
    ]);
};

test('logic expressions are linted', function (mixed $logic, bool $inRow, array $codes) use ($lint, $probeDefinition): void {
    $path = ($inRow ? '/pages/0/elements/2/elements/3' : '/pages/0/elements/3').'/visibleIf';
    $expected = array_map(static fn (string $code): string => 'error|'.$code.'|'.$path, $codes);

    expect($lint($probeDefinition(fs_tree($logic), $inRow)))->toBe($expected);
})->with([
    'boolean' => [true, false, []],
    'unknown var' => [['var' => 'nope'], false, ['unknown_reference']],
    'sub-path of a known key' => [['var' => 'customer.first'], false, []],
    'unknown row key below a repeater' => [['var' => 'items.0.missing'], false, []],
    'unknown first key with sub-path' => [['var' => 'nope.x'], false, ['unknown_reference']],
    'unknown built-in member' => [['var' => '$user.phone'], false, ['unknown_reference']],
    '$row at root' => [['var' => '$row.qty'], false, ['unknown_reference']],
    '$row in a row' => [['var' => '$row.qty'], true, []],
    'row key at root' => [['var' => 'qty'], false, ['unknown_reference']],
    'computed var path' => [['var' => ['cat' => ['n', 'ope']]], false, []],
    'var fallback is checked' => [['var' => ['customer', ['var' => 'nope']]], false, ['unknown_reference']],
    'unknown operator in an iteration body' => [['some' => [['var' => 'items'], ['foo' => [1]]]], false, ['unknown_operator']],
    'references in iteration bodies are not checked' => [['all' => [['var' => 'items'], ['var' => 'nope']]], false, []],
    'nested iteration bodies are not checked' => [['map' => [['var' => 'items'], ['filter' => [['var' => 'parts'], ['var' => 'x']]]]], false, []],
    'iteration source is checked' => [['filter' => [['var' => 'nope'], true]], false, ['unknown_reference']],
    'reduce initial value is checked' => [['reduce' => [['var' => 'items'], ['var' => 'current'], ['var' => 'nope']]], false, ['unknown_reference']],
    'identical findings collapse' => [['and' => [['var' => 'nope'], ['var' => 'nope']]], false, ['unknown_reference']],
    'float literal' => [['dec.mul' => [['var' => 'amount'], 1.5]], false, ['float_literal']],
    'float literal in min' => [['min' => [['var' => 'amount'], 0.5]], false, ['float_literal']],
    'float literal in an iteration body' => [['map' => [['var' => 'items'], ['dec.add' => [['var' => 'qty'], 0.5]]]], false, ['float_literal']],
    'items of a literal array are not operands' => [['sum' => [[1, 2.5]]], false, []],
    'integral float' => [['dec.add' => [['var' => 'amount'], 2.0]], false, []],
    'float outside decimal operators' => [['cat' => [1.5]], false, []],
    'built-in comparison on a number' => [['>' => [['var' => 'amount'], 1]], false, ['decimal_builtin_operator']],
    'built-in comparison via $root in a row' => [['>' => [['var' => '$root.amount'], 1]], true, ['decimal_builtin_operator']],
    'row text shadows the root number' => [['==' => [['var' => 'amount'], '1']], true, []],
    'built-in equality via a row target' => [['==' => [['var' => 'items.0.qty'], '1']], false, ['decimal_builtin_operator']],
    'a repeater row is not a decimal' => [['==' => [['var' => 'items.0'], null]], false, []],
    'built-in operand' => [['<' => [['var' => '$today'], '2027-01-01']], false, []],
    'whole scope operand' => [['!=' => [['var' => ''], null]], false, []],
    'literal operands' => [['+' => [1, 2]], false, []],
    'nested operation operand' => [['<' => [['cat' => [['var' => 'amount']]], 2]], false, []],
    'sum with unknown row key' => [['sum' => [['var' => 'items'], 'nope']], false, ['unknown_reference']],
    'sum with known row key' => [['sum' => [['var' => 'items'], 'qty']], false, []],
    'sum via $root in a row' => [['sum' => [['var' => '$root.items'], 'nope']], true, ['unknown_reference']],
    'max over a nested repeater target' => [['max' => [['var' => 'items.0.parts'], 'nope']], false, ['unknown_reference']],
    'sum over filter is not row-checked' => [['sum' => [['filter' => [['var' => 'items'], true]], 'nope']], false, []],
    'min variadic form is not row-checked' => [['min' => [['var' => 'amount'], ['var' => 'amount']]], false, []],
    'max without row key' => [['max' => [['var' => 'items']]], false, []],
    'sum over a non-repeater' => [['sum' => [['var' => 'customer'], 'x']], false, []],
]);

test('every logic property is linted, expression on any element', function (string $property) use ($lint, $probeDefinition): void {
    expect($lint($probeDefinition(fs_tree(['var' => 'nope']), false, $property)))
        ->toBe(['error|unknown_reference|/pages/0/elements/3/'.$property]);
})->with(['visibleIf', 'requiredIf', 'readOnlyIf', 'default', 'expression']);

test('structural, duplicate, role and numbering rules', function (stdClass $definition, array $expected) use ($lint): void {
    expect($lint($definition))->toBe($expected);
})->with([
    'duplicate id is reported at the element' => fn () => [fs_definition([
        fs_element('text', 'a', ['label' => 'A', 'id' => 'abcdefab-0000-4000-8000-000000000001']),
        fs_element('text', 'b', ['label' => 'B', 'id' => 'ABCDEFAB-0000-4000-8000-000000000001']),
    ]), ['error|duplicate_id|/pages/0/elements/1']],
    'empty ids and keys are not duplicates' => fn () => [fs_definition([
        ['type' => 'divider', 'key' => ''], ['type' => 'divider', 'key' => ''],
        fs_element('select', 's', ['label' => 'S', 'options' => [['value' => 1], ['value' => 1], 'x', ['label' => 'none']]]),
        fs_element('radio', 'r', ['label' => 'R', 'options' => 'x']),
    ]), []],
    'reserved type is reported at the type' => fn () => [fs_definition([fs_element('barcode', 'code', ['label' => 'C'])]), ['error|reserved_type|/pages/0/elements/0/type']],
    'page visibleIf is checked in the root scope' => function () {
        $definition = fs_definition([fs_element('repeater', 'items', ['label' => 'I', 'elements' => [fs_element('text', 'qty', ['label' => 'Q'])]])]);
        $definition->pages[0]->visibleIf = fs_tree(['or' => [['var' => 'qty'], ['var' => 'items'], ['bogus' => []]]]);

        return [$definition, ['error|unknown_operator|/pages/0/visibleIf', 'error|unknown_reference|/pages/0/visibleIf']];
    },
    'signerNameFrom resolves through the scope chain' => fn () => [fs_definition([
        fs_element('text', 'customer', ['label' => 'C']),
        fs_element('repeater', 'items', ['label' => 'I', 'elements' => [
            fs_element('text', 'qty', ['label' => 'Q']),
            fs_element('signature', 'sig', ['label' => 'S', 'signerNameFrom' => 'customer']),
            fs_element('signature', 'sig2', ['label' => 'S', 'signerNameFrom' => 'qty']),
            fs_element('signature', 'sig3', ['label' => 'S', 'signerNameFrom' => '$root.customer']),
            fs_element('signature', 'sig4', ['label' => 'S', 'signerNameFrom' => '$row.qty']),
        ]]),
        fs_element('signature', 'root_sig', ['label' => 'S', 'signerNameFrom' => 'qty']),
        fs_element('signature', 'no_source', ['label' => 'S', 'signerNameFrom' => 5]),
        fs_element('text', 'not_a_signature', ['label' => 'T', 'signerNameFrom' => 'nope']),
        fs_element('signature', 'sub_path', ['label' => 'S', 'signerNameFrom' => 'customer.first']),
        fs_element('signature', 'builtin', ['label' => 'S', 'signerNameFrom' => '$user.name']),
        fs_element('signature', 'row_target', ['label' => 'S', 'signerNameFrom' => 'items.0.qty']),
        fs_element('signature', 'root_prefix', ['label' => 'S', 'signerNameFrom' => '$root.customer']),
    ]), [
        'error|unknown_reference|/pages/0/elements/2/signerNameFrom',
        'error|unknown_reference|/pages/0/elements/5/signerNameFrom',
        'error|unknown_reference|/pages/0/elements/6/signerNameFrom',
        'error|unknown_reference|/pages/0/elements/7/signerNameFrom',
    ]],
    'duplicate role per scope' => fn () => [fs_definition([
        fs_element('text', 'a', ['label' => 'A', 'role' => 'customer.name']),
        fs_element('repeater', 'items', ['label' => 'I', 'elements' => [
            fs_element('text', 'n', ['label' => 'N', 'role' => 'customer.name']),
            fs_element('number', 'q', ['label' => 'Q', 'role' => 'line_item.qty']),
            fs_element('number', 'q2', ['label' => 'Q', 'role' => 'line_item.qty']),
        ]]),
        fs_element('text', 'b', ['label' => 'B', 'role' => 'customer.name']),
        fs_element('text', 'c', ['label' => 'C', 'role' => 7]),
    ]), ['error|duplicate_role|/pages/0/elements/1/elements/2/role', 'error|duplicate_role|/pages/0/elements/2/role']],
    'unknown role repeated' => fn () => [fs_definition([
        fs_element('text', 'a', ['label' => 'A', 'role' => 'x.y']),
        fs_element('text', 'b', ['label' => 'B', 'role' => 'x.y']),
    ]), ['error|duplicate_role|/pages/0/elements/1/role', 'warning|unknown_role|/pages/0/elements/0/role', 'warning|unknown_role|/pages/0/elements/1/role']],
    'repeater bounds and patterns' => fn () => [fs_definition([
        fs_element('repeater', 'r1', ['label' => 'R', 'elements' => [], 'minItems' => 2, 'maxItems' => 2]),
        fs_element('repeater', 'r2', ['label' => 'R', 'elements' => [], 'minItems' => 3, 'maxItems' => 2.0]),
        fs_element('repeater', 'r3', ['label' => 'R', 'elements' => [], 'minItems' => 3, 'maxItems' => 2.5]),
        fs_element('repeater', 'r4', ['label' => 'R', 'elements' => [], 'minItems' => '3', 'maxItems' => 1]),
        fs_element('text', 't0', ['label' => 'T', 'minItems' => 3, 'maxItems' => 1]),
        fs_element('text', 't1', ['label' => 'T', 'validation' => ['pattern' => '^[A-Z]+$']]),
        fs_element('text', 't2', ['label' => 'T', 'validation' => ['pattern' => 5]]),
        fs_element('text', 't3', ['label' => 'T', 'validation' => ['pattern' => '(?<=a)b']]),
    ]), ['error|invalid_pattern|/pages/0/elements/7/validation/pattern', 'error|invalid_repeater_bounds|/pages/0/elements/1']],
    'empty pages' => fn () => [fs_tree(['key' => 'k', 'pages' => [
        ['key' => 'a', 'title' => 'A', 'elements' => []],
        ['key' => 'b', 'title' => 'B'],
        ['key' => 'c', 'title' => 'C', 'elements' => 'none'],
        ['key' => 'd', 'title' => 'D', 'elements' => [fs_element('divider', 'line')]],
        ['key' => '', 'title' => 'E', 'elements' => [fs_element('divider', 'line2')]],
        ['key' => '', 'title' => 'F', 'elements' => [fs_element('divider', 'line3')]],
    ]]), ['warning|empty_page|/pages/0', 'warning|empty_page|/pages/1', 'warning|empty_page|/pages/2']],
    'numbering absent' => fn () => [fs_definition([], ['settings' => ['layout' => 'scroll']]), ['warning|empty_page|/pages/0']],
    'numbering not an object' => fn () => [fs_definition([fs_element('divider', 'd')], ['settings' => ['numbering' => 'SR-{SEQ:4}']]), []],
    'numbering format not a string' => fn () => [fs_definition([fs_element('divider', 'd')], ['settings' => ['numbering' => ['format' => 5]]]), []],
    'numbering reset not a string' => fn () => [fs_definition([fs_element('divider', 'd')], ['settings' => ['numbering' => ['format' => 'N{SEQ:4}', 'reset' => 1]]]), []],
    'numbering invalid' => fn () => [fs_definition([fs_element('divider', 'd')], ['settings' => ['numbering' => ['format' => '{SEQ:13}']]]), ['error|invalid_numbering_format|/settings/numbering/format']],
]);

test('findings carry their params', function (): void {
    $definition = fs_tree(['key' => 'k', 'settings' => ['numbering' => ['format' => 'X-{SEQ:3}', 'reset' => 'yearly']], 'pages' => [
        ['key' => 'p', 'title' => 'P', 'elements' => [
            fs_element('number', 'n', ['label' => 'N', 'id' => 'aaaaaaaa-1111-4000-8000-000000000001']),
            fs_element('text', 'a', ['label' => 'A', 'id' => 'aaaaaaaa-1111-4000-8000-000000000002', 'role' => 'customer.name',
                'validation' => ['pattern' => '('], 'visibleIf' => ['>' => [['var' => 'n'], 1]]]),
            fs_element('text', 'a', ['label' => 'A', 'id' => 'AAAAAAAA-1111-4000-8000-000000000002', 'role' => 'customer.name']),
            fs_element('barcode', 'code', ['label' => 'C']),
            fs_element('select', 's', ['label' => 'S', 'options' => [['value' => 'v', 'label' => 'V'], ['value' => 'v', 'label' => 'W']]]),
            fs_element('repeater', 'r', ['label' => 'R', 'minItems' => 3, 'maxItems' => 1, 'elements' => [fs_element('text', 'x', ['label' => 'X'])]]),
            fs_element('calculated', 'c', ['label' => 'C', 'expression' => ['dec.add' => [['var' => 'c'], 0.5, ['var' => 'nope'], ['foo' => []]]]]),
            fs_element('text', 't', ['label' => 'T', 'role' => 'bogus.role']),
        ]],
        ['key' => 'p', 'title' => 'P2', 'elements' => []],
    ]]);
    $published = fs_definition([
        fs_element('text', 'old', ['label' => 'N', 'id' => 'aaaaaaaa-1111-4000-8000-000000000001']),
        fs_element('text', 'n', ['label' => 'N']),
    ]);
    $byCode = [];
    foreach ((new Linter)->lint($definition, [$published]) as $finding) {
        $byCode[$finding->code] = $finding->toArray();
    }
    $params = array_map(static fn (array $finding): ?array => $finding['params'] ?? null, $byCode);
    ksort($params);

    expect($params)->toBe([
        'cycle' => ['key' => 'c'],
        'decimal_builtin_operator' => ['operator' => '>'],
        'duplicate_id' => ['id' => 'aaaaaaaa-1111-4000-8000-000000000002'],
        'duplicate_key' => ['key' => 'a'],
        'duplicate_option_value' => ['value' => 'v'],
        'duplicate_page_key' => ['key' => 'p'],
        'duplicate_role' => ['role' => 'customer.name'],
        'empty_page' => null,
        'float_literal' => ['operator' => 'dec.add'],
        'invalid_numbering_format' => ['format' => 'X-{SEQ:3}', 'reset' => 'yearly'],
        'invalid_pattern' => ['pattern' => '('],
        'invalid_repeater_bounds' => ['minItems' => 3, 'maxItems' => 1],
        'key_locked' => ['key' => 'n'],
        'key_type_changed' => ['key' => 'n', 'type' => 'number'],
        'reserved_type' => ['type' => 'barcode'],
        'unknown_operator' => ['operator' => 'foo'],
        'unknown_reference' => ['reference' => 'nope'],
        'unknown_role' => ['role' => 'bogus.role'],
    ])->and($byCode['key_locked']['path'])->toBe('/pages/0/elements/0/key')
        ->and($byCode['key_type_changed']['path'])->toBe('/pages/0/elements/0')
        ->and($byCode['empty_page'])->toBe(['severity' => 'warning', 'code' => 'empty_page', 'path' => '/pages/1']);
});

test('published history is checked per scope', function () use ($lint): void {
    $published = fs_definition([
        fs_element('repeater', 'items', ['label' => 'I', 'id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'elements' => [
            fs_element('number', 'qty', ['label' => 'Q', 'id' => 'bbbbbbbb-0000-4000-8000-000000000002']),
            fs_element('text', 'note', ['label' => 'N', 'id' => 'BBBBBBBB-0000-4000-8000-000000000003']),
        ]]),
        fs_element('number', 'total', ['label' => 'T', 'id' => 'bbbbbbbb-0000-4000-8000-000000000004']),
        ['type' => 'number', 'key' => 'z', 'label' => 'Z'],
    ]);
    $current = fs_definition([
        fs_element('repeater', 'items', ['label' => 'I', 'id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'elements' => [
            fs_element('text', 'qty', ['label' => 'Q', 'id' => 'bbbbbbbb-0000-4000-8000-000000000002']),
            fs_element('text', 'remark', ['label' => 'N', 'id' => 'bbbbbbbb-0000-4000-8000-000000000003']),
            fs_element('text', 'total', ['label' => 'Row total']),
        ]]),
        fs_element('text', 'qty', ['label' => 'Root qty']),
        fs_element('number', 'total', ['label' => 'Total renamed label', 'id' => 'bbbbbbbb-0000-4000-8000-000000000004']),
        ['type' => 'text', 'key' => 'z', 'label' => 'Z'],
    ]);
    $expected = [
        'error|key_locked|/pages/0/elements/0/elements/1/key',
        'error|key_type_changed|/pages/0/elements/0/elements/0',
        'error|key_type_changed|/pages/0/elements/3',
    ];

    expect($lint($current, [$published]))->toBe($expected)
        ->and($lint($current, [Definition::fromJson($published)]))->toBe($expected)
        ->and($lint($current, ['x', 1, null, [], true]))->toBe([])
        ->and($lint($current))->toBe([])
        ->and($lint($published, [$published]))->toBe([]);
});

test('an id published with several keys is locked even under one of them', function () use ($lint): void {
    $id = 'cccccccc-0000-4000-8000-000000000001';
    $version = static fn (string $key): stdClass => fs_definition([fs_element('text', $key, ['label' => 'L', 'id' => $id])]);

    expect($lint($version('b'), [$version('a'), $version('b')]))->toBe(['error|key_locked|/pages/0/elements/0/key']);
});

test('lint accepts a Definition and rejects non-objects', function () use ($lint): void {
    $json = fs_definition([fs_element('text', 'a', ['label' => 'A', 'visibleIf' => ['var' => 'nope']])]);

    expect($lint(Definition::fromJson($json)))->toBe($lint($json))
        ->and(fn () => (new Linter)->lint([]))->toThrow(InvalidDefinitionException::class);
});

test('identical findings are reported once', function (): void {
    $findings = (new Linter)->lint(fs_definition([
        fs_element('text', 'a', ['label' => 'A', 'visibleIf' => ['and' => [['var' => 'nope'], ['var' => 'nope'], ['var' => 'other']]]]),
    ]));

    expect(array_map(static fn (LintFinding $f): string => $f->params['reference'], $findings))->toBe(['nope', 'other']);
});

test('isPublishable is false only when an error is present', function (): void {
    expect(Linter::isPublishable([]))->toBeTrue()
        ->and(Linter::isPublishable([LintFinding::warning('empty_page', '/pages/0')]))->toBeTrue()
        ->and(Linter::isPublishable([LintFinding::warning('empty_page', '/pages/0'), LintFinding::error('cycle', '/x')]))->toBeFalse();
});

test('LintFinding factories and serialization', function (): void {
    $error = LintFinding::error('duplicate_key', '/pages/0/elements/1', ['key' => 'a']);
    $warning = LintFinding::warning('empty_page', '/pages/1');

    expect([$error->severity, $error->code, $error->path, $error->params])->toBe(['error', 'duplicate_key', '/pages/0/elements/1', ['key' => 'a']])
        ->and($error->isError())->toBeTrue()
        ->and($warning->isError())->toBeFalse()
        ->and($warning->severity)->toBe(LintFinding::WARNING)
        ->and($error->toArray())->toBe(['severity' => 'error', 'code' => 'duplicate_key', 'path' => '/pages/0/elements/1', 'params' => ['key' => 'a']])
        ->and($warning->toArray())->toBe(['severity' => 'warning', 'code' => 'empty_page', 'path' => '/pages/1'])
        ->and($error->jsonSerialize())->toBe($error->toArray())
        ->and(json_encode($warning))->toBe('{"severity":"warning","code":"empty_page","path":"\/pages\/1"}')
        ->and((new LintFinding('error', 'x', '/'))->params)->toBe([]);
});

test('a custom roles file replaces the bundled vocabulary', function () use ($withTempFile): void {
    $findings = $withTempFile('{"roles": {"custom.role": "A custom role"}}', static fn (string $file): array => fs_finding_set(
        (new Linter($file))->lint(fs_definition([
            fs_element('text', 'a', ['label' => 'A', 'role' => 'custom.role']),
            fs_element('text', 'b', ['label' => 'B', 'role' => 'customer.name']),
        ])),
    ));

    expect($findings)->toBe(['warning|unknown_role|/pages/0/elements/1/role'])
        ->and(fn () => new Linter(sys_get_temp_dir().'/fs-no-roles-'.uniqid().'.json'))->toThrow(RuntimeException::class);
});

test('RoleVocabulary loads role names', function (string $contents, array $roles) use ($withTempFile): void {
    $vocabulary = $withTempFile($contents, static fn (string $file): RoleVocabulary => RoleVocabulary::load($file));

    expect($vocabulary->roles)->toBe($roles)
        ->and($vocabulary->contains('a.b'))->toBe(in_array('a.b', $roles, true));
})->with([
    'roles object' => ['{"roles": {"a.b": "x", "c": "y"}}', ['a.b', 'c']],
    'file without roles' => ['{"version": "1.0.0"}', []],
    'roles not an object' => ['{"roles": ["a.b"]}', []],
    'file not an object' => ['["a.b"]', []],
]);

test('the bundled role vocabulary is loaded by default', function (): void {
    $vocabulary = RoleVocabulary::load();

    expect($vocabulary->contains('customer.name'))->toBeTrue()
        ->and($vocabulary->contains('payment.iban'))->toBeTrue()
        ->and($vocabulary->contains('nope'))->toBeFalse()
        ->and((new RoleVocabulary(['x']))->contains('x'))->toBeTrue();
});
