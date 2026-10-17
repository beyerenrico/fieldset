<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * SSO demo data for development and E2E (M7, owner C): the demo organization's Keycloak provider
 * (docker/keycloak/realm-form-schema.json), its verified domain and claim mappings. Runs after DemoSeeder;
 * never in production. Skeleton: seeds nothing yet.
 */
class SsoDemoSeeder extends Seeder
{
    public function run(): void {}
}
