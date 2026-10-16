<?php

declare(strict_types=1);

use FormSchema\Json\Json;
use FormSchema\Logic\DateMath;
use FormSchema\Logic\DateValue;
use FormSchema\Logic\JsonLogic;

require_once __DIR__.'/../Support/helpers.php';

describe('calendar helpers', function (): void {
    test('isLeapYear follows the Gregorian rules', function (int $year, bool $expected): void {
        expect(DateMath::isLeapYear($year))->toBe($expected);
    })->with([
        [2024, true], [2023, false], [1900, false], [2000, true], [2100, false],
        [2400, true], [0, true], [-4, true], [-100, false], [-400, true],
    ]);

    test('daysInMonth', function (int $year, int $month, int $expected): void {
        expect(DateMath::daysInMonth($year, $month))->toBe($expected);
    })->with([
        [2024, 2, 29], [2023, 2, 28], [2000, 2, 29], [1900, 2, 28], [2024, 4, 30],
        [2024, 6, 30], [2024, 9, 30], [2024, 11, 30], [2024, 1, 31], [2024, 7, 31], [2024, 12, 31],
    ]);

    test('daysFromCivil and civilFromDays convert known dates', function (int $year, int $month, int $day, int $days): void {
        expect(DateMath::daysFromCivil($year, $month, $day))->toBe($days)
            ->and(DateMath::civilFromDays($days))->toBe([$year, $month, $day]);
    })->with([
        'epoch' => [1970, 1, 1, 0],
        'day after epoch' => [1970, 1, 2, 1],
        'day before epoch' => [1969, 12, 31, -1],
        'march 2000' => [2000, 3, 1, 11017],
        'new year 2024' => [2024, 1, 1, 19723],
        'leap day 2024' => [2024, 2, 29, 19782],
        'last representable day' => [9999, 12, 31, 2932896],
        'year 0 start' => [0, 1, 1, -719528],
        'year 0 leap day' => [0, 2, 29, -719469],
        'year 0 march' => [0, 3, 1, -719468],
        'year -1 end' => [-1, 12, 31, -719529],
        'year -400 leap day' => [-400, 2, 29, -865566],
        'year -400 march' => [-400, 3, 1, -865565],
    ]);

    test('daysFromCivil and civilFromDays round-trip and match gmdate', function (): void {
        for ($days = -1_000_000; $days <= 3_000_000; $days += 7919) {
            [$year, $month, $day] = DateMath::civilFromDays($days);

            expect(DateMath::daysFromCivil($year, $month, $day))->toBe($days)
                ->and($month)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(12)
                ->and($day)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(DateMath::daysInMonth($year, $month));

            if ($year >= 1) {
                expect(sprintf('%04d-%02d-%02d', $year, $month, $day))->toBe(gmdate('Y-m-d', $days * 86400));
            }
        }
    });
});

describe('DateValue', function (): void {
    test('parseDate accepts real YYYY-MM-DD dates', function (string $value, array $parts): void {
        $date = DateValue::parseDate($value);

        expect($date)->not->toBeNull()
            ->and([$date->year, $date->month, $date->day])->toBe($parts)
            ->and($date->hasTime)->toBeFalse()
            ->and([$date->hour, $date->minute, $date->second, $date->fraction, $date->offset])->toBe([0, 0, 0, '', '']);
    })->with([
        ['2024-02-29', [2024, 2, 29]],
        ['2000-02-29', [2000, 2, 29]],
        ['0000-02-29', [0, 2, 29]],
        ['9999-12-31', [9999, 12, 31]],
    ]);

    test('parseDate rejects anything else', function (mixed $value): void {
        expect(DateValue::parseDate($value))->toBeNull();
    })->with([
        'no leap year' => ['2023-02-29'],
        'century without leap day' => ['1900-02-29'],
        'month 13' => ['2024-13-01'],
        'month 0' => ['2024-00-10'],
        'april 31' => ['2024-04-31'],
        'day 0' => ['2024-01-00'],
        'day 32' => ['2024-01-32'],
        'short year' => ['24-01-01'],
        'short month' => ['2024-1-01'],
        'trailing newline' => ["2024-01-01\n"],
        'date-time' => ['2024-01-01T00:00:00Z'],
        'fullwidth digits' => ['２０２４-01-01'],
        'empty' => [''],
        'int' => [20240101],
        'null' => [null],
    ]);

    test('parseDateTime accepts RFC 3339 date-times with offset', function (string $value, array $fields): void {
        $dateTime = DateValue::parseDateTime($value);

        expect($dateTime)->not->toBeNull()
            ->and($dateTime->hasTime)->toBeTrue()
            ->and([
                $dateTime->year, $dateTime->month, $dateTime->day, $dateTime->hour,
                $dateTime->minute, $dateTime->second, $dateTime->fraction, $dateTime->offset,
            ])->toBe($fields);
    })->with([
        'UTC' => ['2024-01-01T10:20:30Z', [2024, 1, 1, 10, 20, 30, '', 'Z']],
        'fraction and offset' => ['2024-02-29T23:59:59.5+02:00', [2024, 2, 29, 23, 59, 59, '5', '+02:00']],
        'long fraction' => ['2024-01-01T00:00:00.1234567-05:30', [2024, 1, 1, 0, 0, 0, '1234567', '-05:30']],
        'largest offset' => ['2024-01-01T00:00:00.000+23:59', [2024, 1, 1, 0, 0, 0, '000', '+23:59']],
        'negative zero offset' => ['2024-01-01T00:00:00-00:00', [2024, 1, 1, 0, 0, 0, '', '-00:00']],
    ]);

    test('parseDateTime rejects malformed values', function (mixed $value): void {
        expect(DateValue::parseDateTime($value))->toBeNull();
    })->with([
        'lowercase t' => ['2024-01-01t10:00:00Z'],
        'lowercase z' => ['2024-01-01T10:00:00z'],
        'space separator' => ['2024-01-01 10:00:00Z'],
        'no offset' => ['2024-01-01T10:00:00'],
        'no seconds' => ['2024-01-01T10:00Z'],
        'hour 24' => ['2024-01-01T24:00:00Z'],
        'minute 60' => ['2024-01-01T10:60:00Z'],
        'leap second' => ['2024-01-01T10:00:60Z'],
        'offset hour 24' => ['2024-01-01T10:00:00+24:00'],
        'offset minute 60' => ['2024-01-01T10:00:00+02:60'],
        'offset without colon' => ['2024-01-01T10:00:00+0200'],
        'short offset' => ['2024-01-01T10:00:00+2:00'],
        'empty fraction' => ['2024-01-01T10:00:00.Z'],
        'no leap year' => ['2023-02-29T10:00:00Z'],
        'april 31' => ['2024-04-31T00:00:00Z'],
        'date only' => ['2024-01-01'],
        'int' => [1704103200],
        'null' => [null],
    ]);

    test('parseTime returns seconds since midnight', function (mixed $value, ?int $expected): void {
        expect(DateValue::parseTime($value))->toBe($expected);
    })->with([
        ['00:00', 0], ['09:41', 34860], ['09:41:30', 34890], ['23:59:59', 86399],
        ['24:00', null], ['9:41', null], ['09:60', null], ['09:41:60', null], ['09:41:3', null],
        ['09-41', null], ['09:41 ', null], ['', null], [null, null], [941, null],
    ]);

    test('epochSeconds, instant and dayNumber', function (string $value, int $seconds, string $instant, int $dayNumber): void {
        $parsed = DateValue::parseDateTime($value) ?? DateValue::parseDate($value);

        expect($parsed->epochSeconds())->toBe($seconds)
            ->and((string) $parsed->instant())->toBe($instant)
            ->and($parsed->dayNumber())->toBe($dayNumber);
    })->with([
        'epoch' => ['1970-01-01T00:00:00Z', 0, '0', 0],
        'epoch with offset' => ['1970-01-01T01:00:00+01:00', 0, '0', 0],
        'fraction kept exactly' => ['1970-01-01T00:00:00.0000001Z', 0, '0.0000001', 0],
        'before epoch' => ['1969-12-31T23:59:59.5Z', -1, '-0.5', -1],
        'negative offset keeps local date' => ['2024-01-01T23:00:00-05:00', 1704168000, '1704168000', 19723],
        'date is midnight UTC' => ['1970-01-02', 86400, '86400', 1],
    ]);

    test('offsetSeconds', function (string $offset, int $expected): void {
        expect((new DateValue(true, 2024, 1, 1, offset: $offset))->offsetSeconds())->toBe($expected);
    })->with([
        ['', 0], ['Z', 0], ['+00:00', 0], ['-00:00', 0],
        ['+02:00', 7200], ['-05:30', -19800], ['+23:59', 86340],
    ]);
});

test('date.diff', function (mixed $from, mixed $to, mixed $unit, ?int $expected): void {
    expect(DateMath::diff($from, $to, $unit))->toBe($expected);
})->with([
    'days' => ['2024-01-31', '2024-03-01', 'days', 30],
    'negative days' => ['2024-03-01', '2024-01-31', 'days', -30],
    'leap year days' => ['2023-12-31', '2024-12-31', 'days', 366],
    'same day' => ['2024-01-01', '2024-01-01', 'days', 0],
    'weeks truncated' => ['2024-01-01', '2024-01-14', 'weeks', 1],
    'negative weeks toward zero' => ['2024-01-14', '2024-01-01', 'weeks', -1],
    'two weeks' => ['2024-01-01', '2024-01-15', 'weeks', 2],
    'month end to leap day' => ['2024-01-31', '2024-02-29', 'months', 0],
    'month ends' => ['2024-01-31', '2024-03-31', 'months', 2],
    'negative month ends' => ['2024-03-31', '2024-01-31', 'months', -2],
    'reversed short month' => ['2024-02-29', '2024-01-31', 'months', 0],
    'one day short of a month' => ['2024-01-15', '2024-02-14', 'months', 0],
    'one month' => ['2024-01-15', '2024-02-15', 'months', 1],
    'reversed one day short' => ['2024-02-14', '2024-01-15', 'months', 0],
    'reversed one month' => ['2024-02-15', '2024-01-15', 'months', -1],
    'across a year' => ['2023-11-30', '2024-02-29', 'months', 2],
    'leap day to non-leap year' => ['2020-02-29', '2021-02-28', 'years', 0],
    'leap day to leap day' => ['2020-02-29', '2024-02-29', 'years', 4],
    'negative years toward zero' => ['2024-02-29', '2020-03-01', 'years', -3],
    'reversed short year' => ['2021-02-28', '2020-02-29', 'years', 0],
    'many years' => ['2000-01-01', '2024-06-15', 'years', 24],
    'date with time unit' => ['2024-01-01', '2024-02-01', 'hours', null],
    'unknown unit' => ['2024-01-01', '2024-02-01', 'fortnights', null],
    'non-string unit' => ['2024-01-01', '2024-02-01', 7, null],
    'null unit' => ['2024-01-01', '2024-02-01', null, null],
    'minutes' => ['2024-01-01T00:00:00Z', '2024-01-01T01:30:00Z', 'minutes', 90],
    'hours truncated' => ['2024-01-01T00:00:00Z', '2024-01-01T01:30:00Z', 'hours', 1],
    'negative hours toward zero' => ['2024-01-01T01:30:00Z', '2024-01-01T00:00:00Z', 'hours', -1],
    'days of 24 hours' => ['2024-01-01T00:00:00Z', '2024-01-02T23:59:59Z', 'days', 1],
    'negative days of 24 hours' => ['2024-01-03T00:00:00Z', '2024-01-01T00:00:01Z', 'days', -1],
    'across offsets' => ['2024-01-01T10:00:00+02:00', '2024-01-01T09:00:00Z', 'hours', 1],
    'negative offset' => ['2024-01-01T00:00:00-05:00', '2024-01-01T00:00:00Z', 'hours', -5],
    'same instant across midnight' => ['2024-01-01T23:30:00-01:00', '2024-01-02T00:30:00+00:00', 'minutes', 0],
    'fraction below a minute' => ['2024-01-01T00:00:00.5Z', '2024-01-01T00:01:00Z', 'minutes', 0],
    'fraction above a minute' => ['2024-01-01T00:00:00Z', '2024-01-01T00:01:00.000001Z', 'minutes', 1],
    'exact sub-microsecond fraction' => ['2024-01-01T00:00:00.0000001Z', '2024-01-01T00:01:00Z', 'minutes', 0],
    'negative fraction toward zero' => ['2024-01-01T00:01:00Z', '2024-01-01T00:00:00.000001Z', 'minutes', 0],
    'weeks on date-times' => ['2024-01-01T00:00:00Z', '2024-01-08T00:00:00Z', 'weeks', null],
    'months on date-times' => ['2024-01-01T00:00:00Z', '2024-03-01T00:00:00Z', 'months', null],
    'non-string unit on date-times' => ['2024-01-01T00:00:00Z', '2024-01-01T01:00:00Z', 60, null],
    'date and date-time' => ['2024-01-01', '2024-01-02T00:00:00Z', 'days', null],
    'date-time and date' => ['2024-01-01T00:00:00Z', '2024-01-02', 'days', null],
    'invalid date' => ['2024-02-30', '2024-03-01', 'days', null],
    'null' => [null, '2024-01-01', 'days', null],
    'int' => [20240101, '2024-01-01', 'days', null],
    'lowercase date-time' => ['2024-01-01t00:00:00z', '2024-01-01T00:00:00Z', 'hours', null],
]);

test('date.add', function (mixed $value, mixed $amount, mixed $unit, ?string $expected): void {
    expect(DateMath::add($value, $amount, $unit))->toBe($expected);
})->with([
    'day into leap day' => ['2024-02-28', 1, 'days', '2024-02-29'],
    'day without leap day' => ['2023-02-28', 1, 'days', '2023-03-01'],
    'day backwards' => ['2024-03-01', -1, 'days', '2024-02-29'],
    'zero days' => ['2024-01-01', 0, 'days', '2024-01-01'],
    'new year' => ['2024-12-31', 1, 'days', '2025-01-01'],
    'weeks' => ['2024-01-01', 2, 'weeks', '2024-01-15'],
    'negative weeks' => ['2024-01-15', -3, 'weeks', '2023-12-25'],
    'month clamps to leap day' => ['2024-01-31', 1, 'months', '2024-02-29'],
    'month clamps to 28' => ['2023-01-31', 1, 'months', '2023-02-28'],
    'month clamps to 30' => ['2024-03-31', 1, 'months', '2024-04-30'],
    'negative months across years' => ['2024-03-31', -13, 'months', '2023-02-28'],
    'previous month' => ['2024-01-15', -1, 'months', '2023-12-15'],
    'two years back in months' => ['2024-01-15', -24, 'months', '2022-01-15'],
    'months into next year' => ['2024-11-30', 3, 'months', '2025-02-28'],
    'year from leap day' => ['2024-02-29', 1, 'years', '2025-02-28'],
    'four years from leap day' => ['2024-02-29', 4, 'years', '2028-02-29'],
    'negative year from leap day' => ['2024-02-29', -1, 'years', '2023-02-28'],
    'integral float amount' => ['2024-01-01', 2.0, 'days', '2024-01-03'],
    'integer string amount' => ['2024-01-01', '3', 'days', '2024-01-04'],
    'negative integer string' => ['2024-01-04', '-3', 'days', '2024-01-01'],
    'zero string' => ['2024-01-01', '0', 'days', '2024-01-01'],
    'negative zero string' => ['2024-01-01', '-0', 'days', '2024-01-01'],
    'into year 0' => ['0000-03-01', -1, 'days', '0000-02-29'],
    'year 1 back to year 0' => ['0001-01-01', -1, 'years', '0000-01-01'],
    'before year 0' => ['0000-01-01', -1, 'days', null],
    'after year 9999' => ['9999-12-31', 1, 'days', null],
    'month before year 0' => ['0000-01-31', -1, 'months', null],
    'month after year 9999' => ['9999-12-01', 1, 'months', null],
    'year after 9999' => ['9999-01-01', 1, 'years', null],
    'largest amount leaves the range' => ['2024-01-01', 10000000000, 'days', null],
    'largest negative amount leaves the range' => ['2024-01-01', -10000000000, 'months', null],
    'amount too large' => ['2024-01-01', 10000000001, 'days', null],
    'amount string too large' => ['2024-01-01', '10000000001', 'days', null],
    'amount string too long' => ['2024-01-01', '12345678901234567', 'days', null],
    'int beyond 2^53' => ['2024-01-01', 9007199254740993, 'days', null],
    'fractional amount' => ['2024-01-01', 1.5, 'days', null],
    'infinite amount' => ['2024-01-01', INF, 'days', null],
    'leading zero string' => ['2024-01-01', '07', 'days', null],
    'decimal string' => ['2024-01-01', '1.0', 'days', null],
    'plus sign' => ['2024-01-01', '+1', 'days', null],
    'padded string' => ['2024-01-01', ' 1', 'days', null],
    'bool amount' => ['2024-01-01', true, 'days', null],
    'null amount' => ['2024-01-01', null, 'days', null],
    'null unit' => ['2024-01-01', 1, null, null],
    'non-string unit' => ['2024-01-01', 1, 5, null],
    'hours on a date' => ['2024-01-01', 1, 'hours', null],
    'minutes on a date' => ['2024-01-01', 1, 'minutes', null],
    'unknown unit' => ['2024-01-01', 1, 'decades', null],
    'hour back across midnight' => ['2024-01-01T00:30:00+02:00', -1, 'hours', '2023-12-31T23:30:00+02:00'],
    'minutes keep Z' => ['2024-03-10T12:00:00Z', 90, 'minutes', '2024-03-10T13:30:00Z'],
    'day keeps negative offset' => ['2024-03-10T12:00:00-05:00', 1, 'days', '2024-03-11T12:00:00-05:00'],
    'minute into leap day' => ['2024-02-28T23:59:59+01:00', 1, 'minutes', '2024-02-29T00:00:59+01:00'],
    'fraction dropped' => ['2024-01-01T10:00:00.123456Z', 1, 'minutes', '2024-01-01T10:01:00Z'],
    'fraction dropped on zero' => ['2024-01-01T10:00:00.999Z', 0, 'hours', '2024-01-01T10:00:00Z'],
    'day back' => ['2024-01-01T00:00:00Z', -1, 'days', '2023-12-31T00:00:00Z'],
    'minutes back over a day' => ['2024-01-01T00:00:00Z', -1441, 'minutes', '2023-12-30T23:59:00Z'],
    'string amount on date-time' => ['2024-01-01T00:00:00+00:00', '2', 'hours', '2024-01-01T02:00:00+00:00'],
    'before the epoch' => ['1969-12-31T23:00:00Z', 2, 'hours', '1970-01-01T01:00:00Z'],
    'back before 1900' => ['1900-01-01T00:00:00Z', -1, 'minutes', '1899-12-31T23:59:00Z'],
    'down to year 0 midnight' => ['0000-01-01T00:30:00Z', -30, 'minutes', '0000-01-01T00:00:00Z'],
    'weeks on a date-time' => ['2024-01-01T00:00:00Z', 1, 'weeks', null],
    'months on a date-time' => ['2024-01-01T00:00:00Z', 1, 'months', null],
    'years on a date-time' => ['2024-01-01T00:00:00Z', 1, 'years', null],
    'date-time after year 9999' => ['9999-12-31T23:30:00Z', 1, 'hours', null],
    'date-time before year 0' => ['0000-01-01T00:30:00Z', -1, 'hours', null],
    'invalid date' => ['2024-02-30', 1, 'days', null],
    'invalid date-time' => ['2024-01-01T25:00:00Z', 1, 'hours', null],
    'date-time without offset' => ['2024-01-01T00:00:00', 1, 'hours', null],
    'null value' => [null, 1, 'days', null],
    'int value' => [20240101, 1, 'days', null],
]);

test('date operators', function (string $logic, string $data, mixed $expected): void {
    expect((new JsonLogic)->evaluate(Json::decode($logic), Json::decode($data)))->toBe($expected);
})->with([
    ['{"date.diff": ["2024-01-01", "2024-03-01", "months"]}', '{}', 2],
    ['{"date.diff": [{"var": "a"}, {"var": "b"}, "days"]}', '{"a": "2024-01-01", "b": "2024-01-11"}', 10],
    ['{"date.diff": []}', '{}', null],
    ['{"date.add": ["2024-01-31", 1, "months"]}', '{}', '2024-02-29'],
    ['{"date.add": [{"var": "d"}, "2", "days"]}', '{"d": "2024-12-31"}', '2025-01-02'],
    ['{"date.add": []}', '{}', null],
]);
