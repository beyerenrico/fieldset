<?php

declare(strict_types=1);

use App\Domain\Documents\Branding\Branding;
use App\Domain\Documents\Models\Design;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\CustomerSend\CustomerSendGate;
use App\Domain\Submissions\Models\Submission;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Documents\DocumentFixtures;

it('installs the first organization and owner', function (): void {
    $this->artisan('app:install', [
        '--organization' => 'Müller GmbH', '--name' => 'Max Müller',
        '--email' => 'Max@Example.com', '--password' => 'long-enough-pw', '--locale' => 'de',
    ])->assertSuccessful();

    $org = Organization::query()->sole();
    $owner = User::query()->where('email', 'max@example.com')->sole();
    expect($org->name)->toBe('Müller GmbH')
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and($owner->hasPermissionIn($org, Permission::OrganizationDelete))->toBeTrue();
});

it('is idempotent: a second run changes nothing', function (): void {
    $args = ['--organization' => 'A', '--name' => 'B', '--email' => 'a@example.com', '--password' => 'long-enough-pw'];
    $this->artisan('app:install', $args)->assertSuccessful();
    $this->artisan('app:install', [...$args, '--organization' => 'Second'])->assertSuccessful();

    expect(Organization::query()->pluck('name')->all())->toBe(['A']);
});

it('rejects weak input without creating anything', function (): void {
    $this->artisan('app:install', [
        '--organization' => 'X', '--name' => 'Y', '--email' => 'not-an-email', '--password' => 'short',
    ])->assertFailed();

    expect(Organization::query()->count())->toBe(0);
});

it('seeds one demo user per persona, idempotently', function (): void {
    DocumentFixtures::fakeDisk();
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $org = Organization::query()->where('slug', 'demo')->sole();
    $coordinator = User::query()->where('email', 'coordinator@demo.test')->sole();
    expect($org->members()->count())->toBe(7)
        ->and($coordinator->hasPermissionIn($org, Permission::FormsManage))->toBeTrue()
        ->and($coordinator->hasPermissionIn($org, Permission::SubmissionsReview))->toBeTrue();
});

it('seeds demo branding, the default design and a sendable service report', function (): void {
    DocumentFixtures::fakeDisk();
    $this->seed(DemoSeeder::class);

    $org = Organization::query()->where('slug', 'demo')->sole();
    $branding = Branding::forOrganization($org);
    [$designs, $form] = app(TenantContext::class)->run($org, fn (): array => [
        Design::query()->where('is_default', true)->with('currentVersion')->get(),
        Form::query()->where('key', 'service_report')->with('currentVersion')->sole(),
    ]);
    $probe = new Submission(['form_version_id' => $form->current_version_id]);
    $probe->setRelation('formVersion', $form->currentVersion);

    expect($branding->primaryColor)->toBe('#0f766e')
        ->and($branding->companyName)->toBe('Demo Haustechnik GmbH')
        ->and($branding->company['iban'])->toBe('DE02120300000000202051')
        ->and($branding->logoPath)->not->toBeNull()
        ->and(Storage::disk(DocumentFixtures::DISK)->exists((string) $branding->logoPath))->toBeTrue()
        ->and($designs)->toHaveCount(1)
        ->and($designs->sole()->currentVersion)->not->toBeNull()
        ->and($form->currentVersion?->version)->toBe(1)
        ->and(CustomerSendGate::enabled($probe))->toBeTrue();
});
