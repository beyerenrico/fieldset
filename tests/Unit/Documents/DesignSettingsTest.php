<?php

declare(strict_types=1);

use App\Domain\Documents\Designs\DesignDiff;
use App\Domain\Documents\Designs\DesignSettings;

it('has complete defaults', function (): void {
    $settings = DesignSettings::defaults();

    expect($settings->paper)->toBe('a4')
        ->and($settings->orientation)->toBe('portrait')
        ->and($settings->layout)->toBe('two_column')
        ->and($settings->repeaters)->toBe('auto')
        ->and($settings->photoColumns)->toBe(3)
        ->and($settings->showEmpty)->toBeFalse()
        ->and($settings->watermark)->toBe('auto')
        ->and($settings->locale)->toBeNull()
        ->and($settings->header)->toContain('{{org.name}}')
        ->and($settings->footer)->toContain('{{page}}')
        ->and(array_keys($settings->toArray()))->toBe(['paper', 'orientation', 'margins', 'header', 'footer', 'show_logo', 'layout', 'repeaters', 'photo_columns', 'show_empty', 'signature_placement', 'watermark', 'locale', 'date_style']);
});

it('normalizes input: invalid choices fall back, numbers are clamped, unknown keys dropped', function (): void {
    $settings = DesignSettings::fromArray([
        'paper' => 'a3', 'orientation' => 'landscape', 'margins' => ['top' => '2', 'right' => 99, 'bottom' => 'x', 'left' => 20],
        'photo_columns' => 9, 'show_empty' => '1', 'locale' => 'de-DE; DROP', 'date_style' => 'long', 'evil' => '<script>',
    ]);

    expect($settings->paper)->toBe('a4')
        ->and($settings->orientation)->toBe('landscape')
        ->and($settings->margins)->toBe(['top' => 5, 'right' => 60, 'bottom' => 24, 'left' => 20])
        ->and($settings->photoColumns)->toBe(4)
        ->and($settings->showEmpty)->toBeTrue()
        ->and($settings->locale)->toBeNull()
        ->and($settings->dateStyle)->toBe('long')
        ->and($settings->toArray())->not->toHaveKey('evil')
        ->and(DesignSettings::fromArray($settings->toArray()))->toEqual($settings);
});

it('diffs two settings snapshots setting by setting', function (): void {
    $from = DesignSettings::defaults()->toArray();
    $to = [...$from, 'paper' => 'letter', 'margins' => [...$from['margins'], 'top' => 30]];

    expect(DesignDiff::compare($from, $to))->toBe([
        ['setting' => 'paper', 'before' => 'a4', 'after' => 'letter'],
        ['setting' => 'margins.top', 'before' => '28', 'after' => '30'],
    ])->and(DesignDiff::compare($from, $from))->toBe([]);
});
