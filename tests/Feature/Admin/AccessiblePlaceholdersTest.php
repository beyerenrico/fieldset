<?php

declare(strict_types=1);

// Filament draws text placeholders of empty cells and entries ("Never used") in gray-400, 2.6:1 on white:
// both panels raise them to WCAG 1.4.3 AA (4.5:1), after Filament's own stylesheet so the same selectors win.

it('raises the contrast of table and entry placeholders on every panel', function (string $url): void {
    $light = '.fi-ta-cell .fi-ta-placeholder,.fi-in-entry .fi-in-placeholder{color:var(--gray-500)}';
    $dark = '.fi-ta-cell .fi-ta-placeholder:where(.dark,.dark *),.fi-in-entry .fi-in-placeholder:where(.dark,.dark *){color:var(--gray-400)}';

    $response = $this->get($url)->assertOk();

    expect((string) $response->getContent())
        ->toMatch('#filament/filament/app\.css.*'.preg_quote($light.$dark, '#').'#s');
})->with([
    'admin' => '/admin/login',
    'platform' => '/platform/login',
]);
