<?php

declare(strict_types=1);

use FormSchema\Support\SpecPaths;
use FormSchema\Support\Text;

require_once __DIR__.'/../Support/helpers.php';

test('Text::codePoints splits UTF-8 into code points', function (string $value, array $expected): void {
    expect(Text::codePoints($value))->toBe($expected)
        ->and(Text::length($value))->toBe(count($expected));
})->with([
    'empty' => ['', []],
    'ascii' => ['abc', ['a', 'b', 'c']],
    'multibyte' => ['aä€😀', ['a', 'ä', '€', '😀']],
    'combining mark counts separately' => ["e\u{0301}", ['e', "\u{0301}"]],
    'invalid UTF-8 falls back to bytes' => ["a\xff\xfe", ['a', "\xff", "\xfe"]],
]);

test('Text::length counts code points, not bytes', function (): void {
    expect(Text::length('äöü'))->toBe(3)
        ->and(strlen('äöü'))->toBe(6)
        ->and(Text::length(''))->toBe(0)
        ->and(Text::length("\xc3"))->toBe(1);
});

test('SpecPaths locates the bundled spec files', function (): void {
    $expected = realpath(dirname(__DIR__, 4).'/spec/form-definition/1.0');

    expect(SpecPaths::specDirectory())->toEndWith('/spec/form-definition/1.0')
        ->and(realpath(SpecPaths::specDirectory()))->toBe($expected)
        ->and(SpecPaths::metaSchema())->toBe(SpecPaths::specDirectory().'/meta-schema.json')
        ->and(SpecPaths::roles())->toBe(SpecPaths::specDirectory().'/roles.json')
        ->and(is_file(SpecPaths::metaSchema()))->toBeTrue()
        ->and(is_file(SpecPaths::roles()))->toBeTrue();
});

test('SpecPaths::readJson decodes JSON files', function (): void {
    $roles = SpecPaths::readJson(SpecPaths::roles());
    $file = tempnam(sys_get_temp_dir(), 'fs-json');
    file_put_contents($file, '[1, {"a": []}]');

    try {
        $list = SpecPaths::readJson($file);
    } finally {
        unlink($file);
    }

    expect($roles)->toBeInstanceOf(stdClass::class)
        ->and($roles->roles)->toHaveProperty('customer.name')
        ->and($list[0])->toBe(1)
        ->and($list[1])->toBeInstanceOf(stdClass::class)
        ->and($list[1]->a)->toBe([]);
});

test('SpecPaths::readJson rejects missing files and directories', function (string $path): void {
    expect(fn () => SpecPaths::readJson($path))->toThrow(RuntimeException::class, 'Cannot read "'.$path.'".');
})->with([
    'missing file' => [sys_get_temp_dir().'/fs-does-not-exist-'.uniqid().'.json'],
    'directory' => [sys_get_temp_dir()],
]);

test('SpecPaths::readJson rejects invalid JSON', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'fs-json');
    file_put_contents($file, '{"broken": ');

    try {
        SpecPaths::readJson($file);
        $this->fail('Expected a RuntimeException.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Invalid JSON in "'.$file.'".')
            ->and($exception->getPrevious())->toBeInstanceOf(JsonException::class);
    } finally {
        unlink($file);
    }
});
