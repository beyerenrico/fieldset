<?php

declare(strict_types=1);

/*
| spatie/laravel-pdf (ADR 0028). Documents always render through Gotenberg (Chromium) with no network
| access (BUILD_PROMPT §8.5); keys not set here fall back to the package defaults (merged config).
*/

return [

    'driver' => env('LARAVEL_PDF_DRIVER', 'gotenberg'),

    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),
        'username' => env('GOTENBERG_USERNAME'),
        'password' => env('GOTENBERG_PASSWORD'),
    ],

];
