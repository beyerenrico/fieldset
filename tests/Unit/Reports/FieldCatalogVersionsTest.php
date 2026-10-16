<?php

declare(strict_types=1);

use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Reports\Schema\ReportField;
use App\Domain\Reports\Schema\ReportValue;
use App\Domain\Submissions\Admin\ValueFormatter;

/* Review M5: options merged across versions (M3), per-version elements for money (M1), key collisions. */

/** @param  list<array<string, mixed>>  $elements */
function versionDefinition(array $elements): stdClass
{
    $definition = json_decode((string) json_encode(['pages' => [['key' => 'p', 'elements' => $elements]]]));

    return $definition instanceof stdClass ? $definition : new stdClass;
}

it('merges options across versions with the newest label winning and removed options kept', function (): void {
    $catalog = FieldCatalog::fromDefinitions([
        12 => versionDefinition([['type' => 'select', 'key' => 'kind', 'label' => 'Kind', 'options' => [
            ['value' => 'repair', 'label' => 'Reparatur'], ['value' => 'install', 'label' => 'Montage'],
        ]]]),
        11 => versionDefinition([['type' => 'select', 'key' => 'kind', 'label' => 'Kind', 'options' => [
            ['value' => 'repair', 'label' => 'Repair (old)'], ['value' => 'maintenance', 'label' => 'Maintenance'],
        ]]]),
    ]);

    expect($catalog->get('kind')?->options())->toBe(['repair' => 'Reparatur', 'install' => 'Montage', 'maintenance' => 'Maintenance'])
        ->and(ReportValue::export($catalog->get('kind'), 'maintenance'))->toBe('Maintenance')
        ->and(ReportValue::display($catalog->get('kind'), 'maintenance', new ValueFormatter('en', 'UTC')))->toBe('Maintenance');
});

it('formats money with the currency of the submission version', function (): void {
    $catalog = FieldCatalog::fromDefinitions([
        2 => versionDefinition([['type' => 'money', 'key' => 'amount', 'label' => 'Amount', 'currency' => 'USD', 'scale' => 2]]),
        1 => versionDefinition([['type' => 'money', 'key' => 'amount', 'label' => 'Amount', 'currency' => 'JPY', 'scale' => 0]]),
    ]);
    $amount = $catalog->get('amount');
    $en = new ValueFormatter('en', 'UTC');

    expect($amount)->toBeInstanceOf(ReportField::class)
        ->and(ReportValue::display($amount, '1200', $en, 1))->toBe('1,200 JPY')
        ->and(ReportValue::display($amount, '12.50', $en, 2))->toBe('12.50 USD')
        ->and(ReportValue::display($amount, '12.50', $en))->toBe('12.50 USD')   // unknown version: newest
        ->and($amount?->currencyFor(1))->toBe('JPY')
        ->and($amount?->currencies())->toBe([2 => 'USD', 1 => 'JPY'])
        ->and($amount?->scaleFor('JPY'))->toBe(0)
        ->and($amount?->scaleFor('USD'))->toBe(2);
});

it('detects keys that collide after truncation to 64 characters', function (): void {
    $long = str_repeat('a', 60);
    $catalog = FieldCatalog::fromDefinitions([
        1 => versionDefinition([['type' => 'repeater', 'key' => $long, 'label' => 'Rows', 'elements' => [
            ['type' => 'text', 'key' => 'value_one', 'label' => 'One'],
            ['type' => 'text', 'key' => 'value_two', 'label' => 'Two'],
        ]]]),
    ]);

    expect($catalog->collisions())->toBe([$long.'.val']);
});
