<?php

declare(strict_types=1);

/*
 * Config journey helper (plan U11, R21; ADR 0077), copied into a running instance's app container and run there with
 * `php`: adds an organization (owned by the existing user given as the first argument) with a SAML identity
 * provider, so `fsa config set domain` has an organization whose SSO URLs follow the instance URL to list. Prints
 * `{"organization": {"id": …, "name": …}}`. The image has no dev dependencies, so no factories.
 */

use App\Domain\Identity\Actions\CreateOrganization;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$owner = User::query()->where('email', strtolower((string) ($argv[1] ?? '')))->firstOrFail();
$organization = app(CreateOrganization::class)->handle(['name' => 'Fsa Test SSO Org', 'locale' => 'en'], $owner);
app(TenantContext::class)->run($organization, static function (): void {
    $provider = new IdentityProvider([
        'type' => IdentityProviderType::Saml,
        'name' => 'Fsa Test IdP',
        'config' => ['metadata_url' => 'https://idp.fsa-test.example/metadata', 'email_attribute' => 'email'],
    ]);
    $provider->save();
});

echo json_encode(['organization' => ['id' => $organization->id, 'name' => $organization->name]]), "\n";
