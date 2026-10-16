<?php

declare(strict_types=1);

use FormSchema\Validation\MetaSchemaResult;
use FormSchema\Validation\MetaSchemaValidator;

require_once __DIR__.'/../Support/helpers.php';

$withTempFile = static function (string $contents, callable $callback): mixed {
    $file = tempnam(sys_get_temp_dir(), 'fs-schema');
    file_put_contents($file, $contents);

    try {
        return $callback($file);
    } finally {
        unlink($file);
    }
};

beforeEach(function (): void {
    $this->validator = new MetaSchemaValidator;
});

test('a valid definition has no errors', function (): void {
    $result = $this->validator->validate(fs_definition([fs_element('text', 'a', ['label' => 'A'])]));

    expect($result)->toBeInstanceOf(MetaSchemaResult::class)
        ->and($result->valid)->toBeTrue()
        ->and($result->errors)->toBe([]);
});

test('invalid definitions report a list of {path, message}', function (mixed $definition, array $paths): void {
    $result = $this->validator->validate($definition);

    expect($result->valid)->toBeFalse()
        ->and(array_is_list($result->errors))->toBeTrue()
        ->and(array_column($result->errors, 'path'))->toBe($paths);

    foreach ($result->errors as $error) {
        expect(array_keys($error))->toBe(['path', 'message'])
            ->and($error['message'])->toBeString()->not->toBe('');
    }
})->with([
    'not an object' => fn () => [[], ['']],
    'unknown top-level property' => fn () => [fs_definition([fs_element('text', 'a', ['label' => 'A'])], ['foo' => 1]), ['']],
    'bad element key' => fn () => [fs_definition([fs_element('text', 'Bad Key', ['label' => 'A'])]), ['/pages/0/elements/0/key']],
    'unknown element property' => fn () => [fs_definition([fs_element('text', 'a', ['label' => 'A', 'bogus' => 1])]), ['/pages/0/elements/0']],
    'nested key inside a repeater' => fn () => [
        fs_definition([fs_element('repeater', 'r', ['label' => 'R', 'elements' => [fs_element('text', 'Bad', ['label' => 'A'])]])]),
        ['/pages/0/elements/0/elements/0/key'],
    ],
]);

test('follow-up additionalProperties errors of enclosing objects are dropped', function (): void {
    $definition = fs_definition([fs_element('text', 'a', ['label' => 'A']), fs_element('number', 'b', ['label' => 5])]);
    $result = $this->validator->validate($definition);

    expect($result->errors)->toHaveCount(1)
        ->and($result->errors[0]['path'])->toBe('/pages/0/elements/1/label')
        ->and($result->errors[0]['message'])->toContain('string');
});

test('an additionalProperties error without a nested cause is kept', function (): void {
    $definition = fs_definition([fs_element('text', 'a', ['label' => 'A'])], ['settings' => ['layout' => 'grid']]);
    $definition->pages[0]->bogus = true;
    $paths = array_column($this->validator->validate($definition)->errors, 'path');
    sort($paths);

    expect($paths)->toBe(['/pages/0', '/settings/layout']);
});

test('maxErrors below one still reports an error', function (): void {
    $result = (new MetaSchemaValidator(null, 0))->validate(fs_definition([fs_element('text', 'Bad', ['label' => 'A'])]));

    expect($result->valid)->toBeFalse()
        ->and($result->errors)->not->toBe([]);
});

test('a custom schema path is used, with or without $id', function (string $schema) use ($withTempFile): void {
    [$missing, $present] = $withTempFile($schema, static function (string $file): array {
        $validator = new MetaSchemaValidator($file);

        return [$validator->validate(new stdClass), $validator->validate(fs_tree(['a' => 1]))];
    });

    expect($missing->valid)->toBeFalse()
        ->and($missing->errors)->toBe([['path' => '', 'message' => 'The required properties (a) are missing']])
        ->and($present->valid)->toBeTrue();
})->with([
    'without $id' => ['{"type": "object", "required": ["a"]}'],
    'with $id' => ['{"$id": "https://example.test/custom.json", "type": "object", "required": ["a"]}'],
    'with a non-string $id' => ['{"$id": 5, "type": "object", "required": ["a"]}'],
]);

test('an unreadable schema path throws', function (): void {
    $path = sys_get_temp_dir().'/fs-missing-schema-'.uniqid().'.json';

    expect(fn () => new MetaSchemaValidator($path))->toThrow(RuntimeException::class, 'Cannot read');
});

test('a schema file that is not an object throws', function (string $contents) use ($withTempFile): void {
    expect(fn () => $withTempFile($contents, static fn (string $file) => new MetaSchemaValidator($file)))
        ->toThrow(RuntimeException::class, 'The meta-schema must be a JSON object.');
})->with([
    'array' => ['[]'],
    'string' => ['"schema"'],
    'boolean' => ['true'],
]);

test('a schema file with invalid JSON throws', function () use ($withTempFile): void {
    expect(fn () => $withTempFile('{"type": ', static fn (string $file) => new MetaSchemaValidator($file)))
        ->toThrow(RuntimeException::class, 'Invalid JSON');
});

test('MetaSchemaResult defaults to no errors', function (): void {
    expect((new MetaSchemaResult(true))->errors)->toBe([])
        ->and((new MetaSchemaResult(false, [['path' => '/a', 'message' => 'm']]))->errors)->toBe([['path' => '/a', 'message' => 'm']]);
});
