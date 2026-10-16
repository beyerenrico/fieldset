<?php

declare(strict_types=1);

use App\Domain\Documents\Rendering\ValueFormatter;

it('formats decimal strings with locale symbols, digit for digit', function (): void {
    $de = new ValueFormatter('de-DE', 'Europe/Berlin');
    $en = new ValueFormatter('en-US', 'America/New_York');

    expect($de->decimal('1234567.5'))->toBe('1.234.567,5')
        ->and($de->decimal('-0.25'))->toBe('-0,25')
        ->and($en->decimal('98765432109876543.21'))->toBe('98,765,432,109,876,543.21')   // no float rounding
        ->and($en->decimal('007'))->toBe('7')
        ->and($en->decimal('abc'))->toBe('abc');
});

it('formats money with the locale currency pattern', function (): void {
    expect(new ValueFormatter('de-DE', 'UTC')->money('5850.00', 'EUR'))->toBe("5.850,00\u{a0}€")
        ->and(new ValueFormatter('en-US', 'UTC')->money('1234.5', 'USD'))->toBe('$1,234.5')
        ->and(new ValueFormatter('en-US', 'UTC')->money('-12.00', 'EUR'))->toBe('-€12.00')
        ->and(new ValueFormatter('de-DE', 'UTC')->money('3.10', null))->toBe('3,10');
});

it('formats dates, times and date-times per locale and time zone', function (): void {
    $de = new ValueFormatter('de-DE', 'Europe/Berlin', 'medium');

    expect($de->date('2026-10-16'))->toBe('16.10.2026')
        ->and(new ValueFormatter('en-US', 'UTC', 'long')->date('2026-10-16'))->toBe('October 16, 2026')
        ->and($de->time('09:41'))->toBe('09:41')
        ->and($de->dateTime('2026-10-16T07:41:00Z'))->toBe('16.10.2026, 09:41')
        ->and($de->date('not a date'))->toBe('not a date')
        ->and($de->dateTime(''))->toBe('')                    // never "now": output stays deterministic
        ->and($de->dateTime('tomorrow'))->toBe('tomorrow');
});

it('formats element values by type', function (): void {
    $f = new ValueFormatter('de-DE', 'Europe/Berlin', 'medium', 'de');
    $select = (object) ['type' => 'select', 'options' => [(object) ['value' => 'repair', 'label' => 'Reparatur']]];

    expect($f->format($select, 'repair'))->toBe('Reparatur')
        ->and($f->format((object) ['type' => 'multiselect', 'options' => $select->options], ['repair', 'x']))->toBe('Reparatur, x')
        ->and($f->format((object) ['type' => 'checkbox'], true))->toBe('Ja')
        ->and($f->format((object) ['type' => 'rating', 'max' => 5], 4))->toBe('4 / 5')
        ->and($f->format((object) ['type' => 'calculated', 'format' => 'money', 'currency' => 'EUR'], '97.50'))->toBe("97,50\u{a0}€")
        ->and($f->format((object) ['type' => 'location'], (object) ['lat' => 52.5, 'lng' => 13.4, 'accuracy' => 8]))->toBe('52.5, 13.4 (± 8 m)')
        ->and($f->format((object) ['type' => 'text'], null))->toBe('')
        ->and($f->isNumeric((object) ['type' => 'calculated', 'format' => 'text']))->toBeFalse()
        ->and($f->isNumeric((object) ['type' => 'money']))->toBeTrue();
});
