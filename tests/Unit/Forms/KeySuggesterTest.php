<?php

declare(strict_types=1);

use App\Domain\Forms\Builder\KeySuggester;

it('derives spec-conform keys from labels', function (string $label, string $key): void {
    expect(KeySuggester::fromLabel($label))->toBe($key);
})->with([
    ['Straße & Hausnummer', 'strasse_hausnummer'],
    ['2. Unterschrift', 'field_2_unterschrift'],
    ['   ', ''],
    ['Café Crème', 'cafe_creme'],
    [str_repeat('a', 70), str_repeat('a', 64)],
]);
