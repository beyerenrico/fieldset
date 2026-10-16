# 0002: Stack versions (verified 2026-10-16)
- Status: accepted
- Date: 2026-10-16

## Context
§5/§14.2 require verifying versions before install. Checked Packagist p2 metadata and `npm view`
on 2026-10-16. All match §5.

## Decision
PHP 8.4 baseline. Composer: laravel/framework ^13.37, filament/filament ^5.10, livewire ^4.4.7
(via Filament), laravel/horizon ^5.50, laravel/reverb ^1.12 (optional), laravel/sanctum ^4.3,
laravel/fortify ^1.41, spatie/laravel-permission ^8.3, bezhansalleh/filament-shield ^4.3,
spatie/laravel-activitylog ^5.1, pxlrbt/filament-activity-log ^3.1.2, spatie/laravel-model-states
^2.14, spatie/laravel-medialibrary ^11.23, filament/spatie-laravel-media-library-plugin ^5.10,
spatie/laravel-pdf ^2.14, laravel/socialite ^5.31, dutchcodingcompany/filament-socialite ^3.3,
socialiteproviders/saml2 ^4.10, opis/json-schema ^2.6 (Apache-2.0), jwadhams/json-logic-php ^1.5.1,
brick/math ^1.0, dedoc/scramble ^0.13.48, openspout/openspout ^5.12, pestphp/pest ^5.3 (+laravel ^5.0,
livewire ^5.0, browser ^5.1), larastan ^3.13, pint ^1.32, driftingly/rector-laravel ^2.6.
npm: react/react-dom 19.3, vite 8.3, @vitejs/plugin-react 6.1, @tanstack/react-router 1.170,
@tanstack/react-query 5.104, tailwindcss 4.3, dexie 4.4 (Apache-2.0), vite-plugin-pwa 2.0,
workbox-window 7.4, signature_pad 5.1, json-logic-js 2.0.5, decimal.js 10.6, vitest 5.0,
@playwright/test 1.64, eslint 10, prettier 3.9, ajv 8.20 (meta-schema validation in JS),
sortablejs 1.15, pdf-lib 1.17 (fixture generation only). TypeScript: 7.0.2 is the latest; pin the
newest version supported by typescript-eslint (decided at install, see LEARNINGS).
Services: PostgreSQL 16+, Redis 7 (or Valkey), Gotenberg 8.38, Mailpit, qpdf (queue image).

## Consequences
All licenses are OSI-approved (MIT, Apache-2.0) and compatible with D1. Pre-1.0 Scramble may break.
